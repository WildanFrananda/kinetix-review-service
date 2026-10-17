package peers

import "go.uber.org/zap"

type Logger interface {
	NamedLogger(name string) *zap.Logger
}
