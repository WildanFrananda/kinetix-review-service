package peers

import (
	"context"
	"errors"
	"fmt"
	"sort"
	"strings"

	"go.uber.org/zap"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials"
	"google.golang.org/grpc/peer"
	"google.golang.org/grpc/status"
)

const PluginName = "kinetix_peers"

const defaultTrustDomain = "kinetix.local"

type Plugin struct {
	allowed AllowList
	domains []string
	log     *zap.Logger
}

func (p *Plugin) Init(cfg Configurer, log Logger) error {
	if !cfg.Has(PluginName) {
		return fmt.Errorf("the %s section is missing: an unconfigured server would admit the whole mesh", PluginName)
	}

	var config Config

	err := cfg.UnmarshalKey(PluginName, &config)
	if err != nil {
		return fmt.Errorf("reading the %s section: %w", PluginName, err)
	}

	allowed, err := ParseAllowList(config.Allowed)
	if err != nil {
		return err
	}

	p.allowed = allowed
	p.domains = TrustDomains(config.TrustDomains)
	p.log = log.NamedLogger(PluginName)

	peers := allowed.Peers()
	sort.Strings(peers)
	p.log.Info(
		"gRPC callers allowed on this server",
		zap.Strings("peers", peers),
		zap.Strings("trust_domains", p.domains),
	)

	return nil
}

func (p *Plugin) Name() string {
	return PluginName
}

func (p *Plugin) UnaryServerInterceptor() grpc.UnaryServerInterceptor {
	return p.authorize
}

func (p *Plugin) authorize(
	ctx context.Context,
	req any,
	info *grpc.UnaryServerInfo,
	handler grpc.UnaryHandler,
) (any, error) {
	caller, err := p.caller(ctx)
	if err != nil {
		p.log.Warn(
			"refused a gRPC call from a peer with no SPIFFE identity",
			zap.String("method", info.FullMethod),
			zap.Error(err),
		)

		return nil, status.Error(codes.Unauthenticated, "a client certificate carrying a SPIFFE id is required")
	}

	if !p.allowed.Permits(caller, info.FullMethod) {
		p.log.Warn(
			"refused a gRPC call the allow list does not grant",
			zap.String("method", info.FullMethod),
			zap.String("peer", caller),
		)

		return nil, status.Errorf(codes.PermissionDenied, "service %q may not call %s", caller, info.FullMethod)
	}

	return handler(ctx, req)
}

func (p *Plugin) caller(ctx context.Context) (string, error) {
	from, ok := peer.FromContext(ctx)
	if !ok {
		return "", errors.New("the call carries no peer")
	}

	info, ok := from.AuthInfo.(credentials.TLSInfo)
	if !ok {
		return "", errors.New("the call did not arrive over TLS")
	}

	chains := info.State.VerifiedChains
	if len(chains) == 0 || len(chains[0]) == 0 {
		return "", errors.New("the peer's certificate was not verified against the mesh authority")
	}

	service := ServiceOf(chains[0][0], p.domains)
	if service == "" {
		return "", errors.New("the peer's certificate names no service in a trusted domain")
	}

	return service, nil
}

func TrustDomains(value string) []string {
	domains := []string{}

	for _, raw := range strings.Split(value, ",") {
		domain := strings.TrimSpace(raw)
		if domain != "" {
			domains = append(domains, domain)
		}
	}

	if len(domains) == 0 {
		return []string{defaultTrustDomain}
	}

	return domains
}
