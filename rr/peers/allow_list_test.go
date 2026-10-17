package peers_test

import (
	"testing"

	"github.com/WildanFrananda/kinetix-review-service/rr/peers"
)

const feed = "/review.v1.ReviewEvidenceService/ReviewsSince"

func parse(t *testing.T, value string) peers.AllowList {
	t.Helper()

	allowed, err := peers.ParseAllowList(value)
	if err != nil {
		t.Fatalf("parsing %q: %v", value, err)
	}

	return allowed
}

func TestAnEmptyListIsRefusedRatherThanAdmittingNobodyQuietly(t *testing.T) {
	for _, value := range []string{"", " ", " , , "} {
		_, err := peers.ParseAllowList(value)
		if err == nil {
			t.Errorf("%q parsed, but it names no peer", value)
		}
	}
}

func TestAnEntryWithAnEmptySideIsRefused(t *testing.T) {
	for _, value := range []string{"@review.v1.ReviewEvidenceService", "notary@", "notary@ "} {
		_, err := peers.ParseAllowList(value)
		if err == nil {
			t.Errorf("%q parsed, but one side of the @ is empty", value)
		}
	}
}

func TestABareEntryMayCallEveryMethod(t *testing.T) {
	allowed := parse(t, " notary , order ")

	for _, method := range []string{feed, "/review.v1.Another/Method"} {
		if !allowed.Permits("notary", method) {
			t.Errorf("notary was refused %s", method)
		}
	}

	if allowed.Permits("search", feed) {
		t.Error("search is not listed, but was let through")
	}
}

func TestAServiceEntryCoversEveryMethodOfThatServiceOnly(t *testing.T) {
	allowed := parse(t, "notary@review.v1.ReviewEvidenceService")

	if !allowed.Permits("notary", feed) {
		t.Error("notary was refused a method of the service it is granted")
	}

	if allowed.Permits("notary", "/review.v1.Another/Method") {
		t.Error("notary reached a service it is not granted")
	}
}

func TestAMethodEntryCoversThatMethodAndNoOther(t *testing.T) {
	allowed := parse(t, "notary@review.v1.ReviewEvidenceService/ReviewsSince,order@shipping.v1.Quote")

	if !allowed.Permits("notary", feed) {
		t.Error("notary was refused the method it is granted")
	}

	if allowed.Permits("notary", "/review.v1.ReviewEvidenceService/Other") {
		t.Error("a method grant covered another method of the same service")
	}

	if allowed.Permits("order", feed) {
		t.Error("order's grant leaked onto the feed")
	}

	if !allowed.Permits("order", "/shipping.v1.Quote/Price") {
		t.Error("order's own service grant does not hold")
	}
}

func TestAListedPeerMayAskForHealthAndNobodyElse(t *testing.T) {
	allowed := parse(t, "notary@review.v1.ReviewEvidenceService/ReviewsSince")

	if !allowed.Permits("notary", "/grpc.health.v1.Health/Check") {
		t.Error("a listed peer was refused the health check")
	}

	if allowed.Permits("search", "/grpc.health.v1.Health/Check") {
		t.Error("an unlisted peer was answered the health check")
	}
}
