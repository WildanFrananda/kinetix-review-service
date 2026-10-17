package peers

import (
	"crypto/x509"
	"strings"
)

func ServiceOf(certificate *x509.Certificate, trustDomains []string) string {
	for _, uri := range certificate.URIs {
		id := uri.String()

		for _, domain := range trustDomains {
			name, found := strings.CutPrefix(id, "spiffe://"+domain+"/service/")
			if found && name != "" && !strings.Contains(name, "/") {
				return name
			}
		}
	}

	return ""
}
