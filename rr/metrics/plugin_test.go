package metrics_test

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/status"

	"github.com/WildanFrananda/kinetix-review-service/rr/metrics"
)

const proto = `syntax = "proto3";

package review.v1;

service ReviewEvidenceService {
  rpc ReviewsSince(ReviewsSinceRequest) returns (ReviewsSinceResponse);
}

message ReviewsSinceRequest {}

message ReviewsSinceResponse {}
`

const feed = "/review.v1.ReviewEvidenceService/ReviewsSince"

type configurer struct {
	config metrics.Config
	protos []string
}

func (c configurer) Has(string) bool {
	return true
}

func (c configurer) UnmarshalKey(_ string, out any) error {
	switch target := out.(type) {
	case *metrics.Config:
		*target = c.config
	case *metrics.GrpcSection:
		target.Proto = c.protos
	}

	return nil
}

func protoFile(t *testing.T, body string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "review.proto")

	err := os.WriteFile(path, []byte(body), 0o600)
	if err != nil {
		t.Fatal(err)
	}

	return path
}

func config() metrics.Config {
	return metrics.Config{
		Service: "kinetix-review-service",
		Version: "1.2.3",
		Address: "127.0.0.1:0",
	}
}

func started(t *testing.T) *metrics.Plugin {
	t.Helper()

	p := &metrics.Plugin{}

	err := p.Init(configurer{config: config(), protos: []string{protoFile(t, proto)}})
	if err != nil {
		t.Fatalf("init: %v", err)
	}

	return p
}

func get(t *testing.T, p *metrics.Plugin, path string) (int, string) {
	t.Helper()

	recorder := httptest.NewRecorder()
	p.Handler().ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, path, nil))

	body, err := io.ReadAll(recorder.Result().Body)
	if err != nil {
		t.Fatal(err)
	}

	return recorder.Code, string(body)
}

func line(text string, prefix string, labels ...string) string {
	for _, candidate := range strings.Split(text, "\n") {
		if !strings.HasPrefix(candidate, prefix) {
			continue
		}

		matched := true

		for _, label := range labels {
			if !strings.Contains(candidate, label) {
				matched = false
			}
		}

		if matched {
			return candidate
		}
	}

	return ""
}

func TestEveryMethodOfTheContractIsPublishedAtZeroBeforeItIsCalled(t *testing.T) {
	_, text := get(t, started(t), "/metrics")

	seeded := line(text, "kinetix_grpc_server_calls_total", `grpc_method="`+feed+`"`, `grpc_code="OK"`)
	if !strings.HasSuffix(seeded, " 0") {
		t.Errorf("the feed is not published at zero: %q", seeded)
	}

	build := line(text, "kinetix_build_info", `service="kinetix-review-service"`, `version="1.2.3"`)
	if !strings.HasSuffix(build, " 1") {
		t.Errorf("no build info: %q", build)
	}
}

func TestEachScrapeIsCountedAsTheHttpRequestItIs(t *testing.T) {
	p := started(t)

	_, _ = get(t, p, "/metrics")
	code, _ := get(t, p, "/elsewhere")
	_, text := get(t, p, "/metrics")

	if code != http.StatusNotFound {
		t.Errorf("an unknown path answered %d", code)
	}

	scraped := line(text, "kinetix_http_requests_total", `route="/metrics"`, `status="200"`)
	if !strings.HasSuffix(scraped, " 1") {
		t.Errorf("the first scrape is not counted by the second: %q", scraped)
	}

	stray := line(text, "kinetix_http_requests_total", `route="(unmatched)"`, `status="404"`)
	if !strings.HasSuffix(stray, " 1") {
		t.Errorf("an unknown path is not counted under (unmatched): %q", stray)
	}

	if line(text, "kinetix_http_request_duration_seconds_count", `route="/metrics"`) == "" {
		t.Error("no duration histogram for the scrape route")
	}
}

func TestACallIsCountedWithTheCodeItReturned(t *testing.T) {
	p := started(t)
	info := &grpc.UnaryServerInfo{FullMethod: feed}

	_, _ = p.UnaryServerInterceptor()(context.Background(), nil, info, func(context.Context, any) (any, error) {
		return nil, status.Error(codes.PermissionDenied, "no")
	})
	_, _ = p.UnaryServerInterceptor()(context.Background(), nil, info, func(context.Context, any) (any, error) {
		return "ok", nil
	})

	_, text := get(t, p, "/metrics")

	refused := line(text, "kinetix_grpc_server_calls_total", `grpc_code="PermissionDenied"`)
	if !strings.HasSuffix(refused, " 1") {
		t.Errorf("the refusal was not counted under its code: %q", refused)
	}

	if got := line(text, "kinetix_grpc_server_calls_total", `grpc_code="OK"`); !strings.HasSuffix(got, " 1") {
		t.Errorf("the answered call was not counted: %q", got)
	}
}

func TestTheEndpointServesOnItsAddressAndStops(t *testing.T) {
	p := started(t)

	failures := p.Serve()
	select {
	case err := <-failures:
		t.Fatalf("serve: %v", err)
	default:
	}

	err := p.Stop(context.Background())
	if err != nil {
		t.Fatalf("stop: %v", err)
	}
}

func TestAContractWithNoMethodStopsTheServer(t *testing.T) {
	err := (&metrics.Plugin{}).Init(
		configurer{
			config: config(),
			protos: []string{protoFile(t, "syntax = \"proto3\";\n\npackage review.v1;\n")},
		},
	)
	if err == nil {
		t.Error("started with nothing to serve")
	}
}

func TestTheServiceVersionAndAddressAreRequired(t *testing.T) {
	for _, missing := range []metrics.Config{
		{Version: "1", Address: "127.0.0.1:0"},
		{Service: "kinetix-review-service", Address: "127.0.0.1:0"},
		{Service: "kinetix-review-service", Version: "1"},
	} {
		err := (&metrics.Plugin{}).Init(
			configurer{
				config: missing,
				protos: []string{protoFile(t, proto)},
			},
		)
		if err == nil {
			t.Errorf("started with %+v", missing)
		}
	}
}
