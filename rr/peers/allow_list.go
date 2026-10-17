package peers

import (
	"errors"
	"fmt"
	"strings"
)

const healthService = "grpc.health.v1.Health"

type AllowList struct {
	scopes map[string]map[string]struct{}
}

func ParseAllowList(value string) (AllowList, error) {
	scopes := map[string]map[string]struct{}{}

	for _, raw := range strings.Split(value, ",") {
		entry := strings.TrimSpace(raw)
		if entry == "" {
			continue
		}

		peer, scope, _ := strings.Cut(entry, "@")
		peer = strings.TrimSpace(peer)
		scope = strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(scope), "/"))

		if peer == "" {
			return AllowList{}, fmt.Errorf("the allow list entry %q names no peer", entry)
		}

		if strings.Contains(entry, "@") && scope == "" {
			return AllowList{}, fmt.Errorf("the allow list entry %q names no service after the @", entry)
		}

		if scopes[peer] == nil {
			scopes[peer] = map[string]struct{}{}
		}

		scopes[peer][scope] = struct{}{}
	}

	if len(scopes) == 0 {
		return AllowList{}, errors.New("the allow list names no peer, so nothing could call this server")
	}

	return AllowList{scopes: scopes}, nil
}

func (a AllowList) Permits(peer string, fullMethod string) bool {
	scopes, listed := a.scopes[peer]
	if !listed {
		return false
	}

	if _, everything := scopes[""]; everything {
		return true
	}

	method := strings.TrimPrefix(fullMethod, "/")
	service, _, _ := strings.Cut(method, "/")

	if service == healthService {
		return true
	}

	_, byService := scopes[service]
	_, byMethod := scopes[method]

	return byService || byMethod
}

func (a AllowList) Peers() []string {
	peers := make([]string, 0, len(a.scopes))

	for peer := range a.scopes {
		peers = append(peers, peer)
	}

	return peers
}
