package metrics

type Config struct {
	Service string `mapstructure:"service"`
	Version string `mapstructure:"version"`
	Address string `mapstructure:"address"`
}
