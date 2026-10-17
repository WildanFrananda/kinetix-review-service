package peers_test

import (
	"testing"

	"github.com/WildanFrananda/kinetix-review-service/rr/peers"
)

func TestTheServiceIsReadFromATrustedSpiffeID(t *testing.T) {
	domains := []string{"kinetix.local", "prod.kinetix"}

	cases := map[string]string{
		"spiffe://kinetix.local/service/notary":       "notary",
		"spiffe://prod.kinetix/service/order":         "order",
		"spiffe://elsewhere.example/service/notary":   "",
		"spiffe://kinetix.local/service/":             "",
		"spiffe://kinetix.local/service/notary/extra": "",
		"spiffe://kinetix.local/workload/notary":      "",
		"https://kinetix.local/service/notary":        "",
	}

	for uri, want := range cases {
		got := peers.ServiceOf(leaf(t, uri), domains)
		if got != want {
			t.Errorf("%s read as %q, want %q", uri, got, want)
		}
	}
}

func TestACertificateWithNoUriNamesNoService(t *testing.T) {
	if got := peers.ServiceOf(leaf(t), []string{"kinetix.local"}); got != "" {
		t.Errorf("read %q from a certificate with no URI", got)
	}
}

func TestTheTrustDomainsDefaultToTheLabsOnly(t *testing.T) {
	got := peers.TrustDomains(" , ")
	if len(got) != 1 || got[0] != "kinetix.local" {
		t.Errorf("an empty setting gave %v", got)
	}

	got = peers.TrustDomains("prod.kinetix, kinetix.local")
	if len(got) != 2 || got[0] != "prod.kinetix" || got[1] != "kinetix.local" {
		t.Errorf("a list gave %v", got)
	}
}
