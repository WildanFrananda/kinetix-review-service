package peers

type Config struct {
	Allowed      string `mapstructure:"allowed"`
	TrustDomains string `mapstructure:"trust_domains"`
}
