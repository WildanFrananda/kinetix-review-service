package peers_test

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"testing"

	"go.uber.org/zap"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials"
	"google.golang.org/grpc/peer"
	"google.golang.org/grpc/status"

	"github.com/WildanFrananda/kinetix-review-service/rr/peers"
)

type configurer struct {
	present bool
	config  peers.Config
}

func (c configurer) Has(name string) bool {
	return c.present && name == peers.PluginName
}

func (c configurer) UnmarshalKey(_ string, out any) error {
	*out.(*peers.Config) = c.config

	return nil
}

type logger struct{}

func (logger) NamedLogger(string) *zap.Logger {
	return zap.NewNop()
}

func plugin(t *testing.T, allowed string) *peers.Plugin {
	t.Helper()

	p := &peers.Plugin{}

	err := p.Init(
		configurer{
			present: true,
			config: peers.Config{
				Allowed:      allowed,
				TrustDomains: "kinetix.local",
			},
		},
		logger{},
	)
	if err != nil {
		t.Fatalf("init: %v", err)
	}

	return p
}

func over(state tls.ConnectionState) context.Context {
	return peer.NewContext(
		context.Background(),
		&peer.Peer{AuthInfo: credentials.TLSInfo{State: state}},
	)
}

func verified(certificate *x509.Certificate) context.Context {
	return over(tls.ConnectionState{VerifiedChains: [][]*x509.Certificate{{certificate}}})
}

func call(t *testing.T, p *peers.Plugin, ctx context.Context) (bool, codes.Code) {
	t.Helper()

	reached := false
	handler := func(context.Context, any) (any, error) {
		reached = true

		return "answered", nil
	}

	_, err := p.UnaryServerInterceptor()(ctx, nil, &grpc.UnaryServerInfo{FullMethod: feed}, handler)

	return reached, status.Code(err)
}

func TestAGrantedPeerReachesTheHandler(t *testing.T) {
	p := plugin(t, "notary@review.v1.ReviewEvidenceService/ReviewsSince")

	reached, code := call(t, p, verified(leaf(t, "spiffe://kinetix.local/service/notary")))
	if !reached || code != codes.OK {
		t.Fatalf("reached=%v code=%v", reached, code)
	}
}

func TestAPeerTheListDoesNotGrantIsDenied(t *testing.T) {
	p := plugin(t, "notary@review.v1.ReviewEvidenceService/ReviewsSince")

	reached, code := call(t, p, verified(leaf(t, "spiffe://kinetix.local/service/search")))
	if reached || code != codes.PermissionDenied {
		t.Fatalf("reached=%v code=%v", reached, code)
	}
}

func TestAPeerWithoutAVerifiedSpiffeIdentityIsUnauthenticated(t *testing.T) {
	p := plugin(t, "notary")
	notary := leaf(t, "spiffe://kinetix.local/service/notary")

	cases := map[string]context.Context{
		"no peer at all":           context.Background(),
		"a peer not over TLS":      peer.NewContext(context.Background(), &peer.Peer{}),
		"a certificate unverified": over(tls.ConnectionState{PeerCertificates: []*x509.Certificate{notary}}),
		"a foreign trust domain":   verified(leaf(t, "spiffe://elsewhere.example/service/notary")),
		"no SPIFFE id":             verified(leaf(t)),
	}

	for name, ctx := range cases {
		reached, code := call(t, p, ctx)
		if reached || code != codes.Unauthenticated {
			t.Errorf("%s: reached=%v code=%v", name, reached, code)
		}
	}
}

func TestTheServerWillNotStartWithoutAnAllowList(t *testing.T) {
	err := (&peers.Plugin{}).Init(configurer{present: false}, logger{})
	if err == nil {
		t.Error("started with no kinetix_peers section")
	}

	err = (&peers.Plugin{}).Init(configurer{present: true}, logger{})
	if err == nil {
		t.Error("started with an empty allow list")
	}
}
