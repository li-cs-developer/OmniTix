// Package redis provides the Redis client pool and atomic hold primitives
// for the OmniTix inventory engine.
package redis

import (
	"context"
	"fmt"
	"time"

	"github.com/omnitix/go-inventory/internal/config"
	"github.com/omnitix/go-inventory/internal/logger"
	goredis "github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// Client wraps the go-redis client with OmniTix-specific helpers.
type Client struct {
	*goredis.Client
	log *logger.Logger
}

// New creates a Redis client from config. It does NOT connect immediately —
// go-redis dials lazily. Call WaitUntilReady to verify connectivity.
func New(cfg *config.RedisConfig, log *logger.Logger) *Client {
	client := goredis.NewClient(&goredis.Options{
		Addr:         cfg.Addr(),
		Password:     cfg.Password,
		DB:           cfg.DB,
		PoolSize:     cfg.PoolSize,
		MinIdleConns: cfg.MinIdleConns,
		DialTimeout:  cfg.DialTimeout,
		ReadTimeout:  cfg.ReadTimeout,
		WriteTimeout: cfg.WriteTimeout,

		// Retry policy: 3 attempts with backoff. Redis blips shouldn't
		// kill a hold request unless they persist.
		MaxRetries:      3,
		MinRetryBackoff: 8 * time.Millisecond,
		MaxRetryBackoff: 512 * time.Millisecond,

		// Connection identity for debugging in Redis MONITOR / CLIENT LIST.
		ClientName: "omnitix-go-inventory",

		// Context timeout for pipelined commands.
		ContextTimeoutEnabled: true,
	})

	return &Client{
		Client: client,
		log:    log.Named("redis"),
	}
}

// WaitUntilReady pings Redis until it responds or the context expires.
// Used at service startup to fail fast if Redis is unreachable.
func (c *Client) WaitUntilReady(ctx context.Context, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)

	for {
		if time.Now().After(deadline) {
			return fmt.Errorf("redis not ready after %s", timeout)
		}

		pingCtx, cancel := context.WithTimeout(ctx, 2*time.Second)
		err := c.Ping(pingCtx).Err()
		cancel()

		if err == nil {
			c.log.Info("redis is ready", zap.String("addr", c.Options().Addr))
			return nil
		}

		c.log.Debug("redis not ready yet, retrying",
			zap.Error(err),
			zap.Duration("remaining", time.Until(deadline)),
		)

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
			// retry
		}
	}
}

// Health checks Redis connectivity. Returns nil on success.
func (c *Client) Health(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	return c.Ping(ctx).Err()
}

// Close flushes the pool and closes all connections.
func (c *Client) Close() error {
	return c.Client.Close()
}