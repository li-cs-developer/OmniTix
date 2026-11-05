package redis

import (
	"github.com/omnitix/go-inventory/internal/config"
	"github.com/omnitix/go-inventory/internal/logger"
	goredis "github.com/redis/go-redis/v9"
)

// NewWithClient wraps an already-constructed go-redis client. Used by tests
// that inject a miniredis instance.
func NewWithClient(client *goredis.Client, _ *config.RedisConfig, log *logger.Logger) *Client {
	return &Client{
		Client: client,
		log:    log.Named("redis"),
	}
}