package peers

type Configurer interface {
	UnmarshalKey(name string, out any) error
	Has(name string) bool
}
