package metrics

import (
	"fmt"
	"path/filepath"

	"github.com/roadrunner-server/grpc/v5/parser"
)

func Methods(protoFiles []string) ([]string, error) {
	methods := []string{}

	for _, file := range protoFiles {
		services, err := parser.File(file, filepath.Dir(file))
		if err != nil {
			return nil, fmt.Errorf("reading the services in %s: %w", file, err)
		}

		for _, service := range services {
			for _, method := range service.Methods {
				methods = append(methods, "/"+service.Package+"."+service.Name+"/"+method.Name)
			}
		}
	}

	if len(methods) == 0 {
		return nil, fmt.Errorf("the proto files %v declare no method this server could serve", protoFiles)
	}

	return methods, nil
}
