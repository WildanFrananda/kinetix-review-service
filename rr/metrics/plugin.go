package metrics

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"strconv"
	"time"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/collectors"
	"github.com/prometheus/client_golang/prometheus/promhttp"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/status"
)

const PluginName = "kinetix_metrics"

const path = "/metrics"

const unmatched = "(unmatched)"

type Plugin struct {
	address  string
	registry *prometheus.Registry
	served   *prometheus.CounterVec
	requests *prometheus.CounterVec
	duration *prometheus.HistogramVec
	server   *http.Server
}

func (p *Plugin) Init(cfg Configurer) error {
	var config Config

	err := cfg.UnmarshalKey(PluginName, &config)
	if err != nil {
		return fmt.Errorf("reading the %s section: %w", PluginName, err)
	}

	if config.Service == "" || config.Version == "" || config.Address == "" {
		return fmt.Errorf("the %s section must name the service, its version and the address to serve", PluginName)
	}

	var section GrpcSection

	err = cfg.UnmarshalKey("grpc", &section)
	if err != nil {
		return fmt.Errorf("reading the grpc section: %w", err)
	}

	methods, err := Methods(section.Proto)
	if err != nil {
		return err
	}

	p.address = config.Address
	p.registry = prometheus.NewRegistry()
	p.registry.MustRegister(collectors.NewGoCollector())
	p.registry.MustRegister(collectors.NewProcessCollector(collectors.ProcessCollectorOpts{}))

	build := prometheus.NewGaugeVec(
		prometheus.GaugeOpts{
			Name: "kinetix_build_info",
			Help: "Always 1. Carries the service name and build version as labels.",
		},
		[]string{"service", "version"},
	)
	build.WithLabelValues(config.Service, config.Version).Set(1)

	p.served = prometheus.NewCounterVec(
		prometheus.CounterOpts{
			Name: "kinetix_grpc_server_calls_total",
			Help: "gRPC calls this service answered, by method and the code it returned.",
		},
		[]string{"grpc_method", "grpc_code"},
	)

	p.requests = prometheus.NewCounterVec(
		prometheus.CounterOpts{
			Name: "kinetix_http_requests_total",
			Help: "HTTP requests served, by method, route template and status.",
		},
		[]string{"method", "route", "status"},
	)

	p.duration = prometheus.NewHistogramVec(
		prometheus.HistogramOpts{
			Name:    "kinetix_http_request_duration_seconds",
			Help:    "HTTP request duration in seconds.",
			Buckets: prometheus.DefBuckets,
		},
		[]string{"method", "route"},
	)

	p.registry.MustRegister(build, p.served, p.requests, p.duration)

	for _, method := range methods {
		p.served.WithLabelValues(method, codes.OK.String())
	}

	p.requests.WithLabelValues(http.MethodGet, path, strconv.Itoa(http.StatusOK))
	p.duration.WithLabelValues(http.MethodGet, path)

	return nil
}

func (p *Plugin) Name() string {
	return PluginName
}

func (p *Plugin) Handler() http.Handler {
	scrape := promhttp.HandlerFor(p.registry, promhttp.HandlerOpts{Registry: p.registry})

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		started := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}
		route := unmatched

		if r.URL.Path == path {
			route = path
			scrape.ServeHTTP(recorder, r)
		} else {
			http.NotFound(recorder, r)
		}

		p.requests.WithLabelValues(r.Method, route, strconv.Itoa(recorder.status)).Inc()
		p.duration.WithLabelValues(r.Method, route).Observe(time.Since(started).Seconds())
	})
}

func (p *Plugin) Serve() chan error {
	failures := make(chan error, 1)

	listener, err := net.Listen("tcp", p.address)
	if err != nil {
		failures <- fmt.Errorf("the metrics endpoint cannot listen on %s: %w", p.address, err)

		return failures
	}

	p.server = &http.Server{
		Handler:           p.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		err := p.server.Serve(listener)
		if err != nil && !errors.Is(err, http.ErrServerClosed) {
			failures <- fmt.Errorf("the metrics endpoint stopped: %w", err)
		}
	}()

	return failures
}

func (p *Plugin) Stop(ctx context.Context) error {
	if p.server == nil {
		return nil
	}

	return p.server.Shutdown(ctx)
}

func (p *Plugin) UnaryServerInterceptor() grpc.UnaryServerInterceptor {
	return p.count
}

func (p *Plugin) count(
	ctx context.Context,
	req any,
	info *grpc.UnaryServerInfo,
	handler grpc.UnaryHandler,
) (any, error) {
	reply, err := handler(ctx, req)
	p.served.WithLabelValues(info.FullMethod, status.Code(err).String()).Inc()

	return reply, err
}
