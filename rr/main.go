package main

import (
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	configPlugin "github.com/roadrunner-server/config/v5"
	"github.com/roadrunner-server/endure/v2"
	grpcPlugin "github.com/roadrunner-server/grpc/v5"
	"github.com/roadrunner-server/logger/v5"
	"github.com/roadrunner-server/server/v5"
	"github.com/roadrunner-server/status/v5"

	"github.com/WildanFrananda/kinetix-review-service/rr/metrics"
	"github.com/WildanFrananda/kinetix-review-service/rr/peers"
)

const roadRunnerVersion = "2025.1.15"

const gracePeriod = 30 * time.Second

func main() {
	err := run(os.Args[1:])
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run(args []string) error {
	flags := flag.NewFlagSet("rr", flag.ContinueOnError)
	path := flags.String("c", ".rr.yaml", "the RoadRunner configuration file")

	err := flags.Parse(args)
	if err != nil {
		return err
	}

	container := endure.New(slog.LevelError, endure.GracefulShutdownTimeout(gracePeriod))

	err = container.RegisterAll(
		&configPlugin.Plugin{
			Path:    *path,
			Timeout: gracePeriod,
			Version: roadRunnerVersion,
		},
		&logger.Plugin{},
		&server.Plugin{},
		&status.Plugin{},
		&metrics.Plugin{},
		&peers.Plugin{},
		&grpcPlugin.Plugin{},
	)
	if err != nil {
		return fmt.Errorf("registering the plugins: %w", err)
	}

	err = container.Init()
	if err != nil {
		return fmt.Errorf("initialising the plugins: %w", err)
	}

	failures, err := container.Serve()
	if err != nil {
		return fmt.Errorf("starting the plugins: %w", err)
	}

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)

	select {
	case failure := <-failures:
		stopErr := container.Stop()

		return errors.Join(
			fmt.Errorf("plugin %s failed: %w", failure.VertexID, failure.Error),
			stopErr,
		)
	case <-stop:
		return container.Stop()
	}
}
