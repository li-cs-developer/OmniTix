// Package config loads OmniTix Inventory Engine settings from environment
// variables. Local development supports a .env file; production reads
// env vars directly.
package config

import (
	"fmt"
	"time"

	"github.com/joho/godotenv"
	"github.com/kelseyhightower/envconfig"
)

// Config holds all runtime settings for the inventory service.
type Config struct {
	// ─── Environment ───────────────────────────────────────
	Env      string `envconfig:"APP_ENV" default:"local"`
	LogLevel string `envconfig:"LOG_LEVEL" default:"debug"`

	// ─── gRPC server ───────────────────────────────────────
	GRPCPort int `envconfig:"INVENTORY_GRPC_PORT" default:"50051"`

	// ─── HTTP server (health + metrics) ────────────────────
	HTTPPort int `envconfig:"INVENTORY_HTTP_PORT" default:"8080"`

	// ─── Redis ─────────────────────────────────────────────
	Redis RedisConfig

	// ─── Kafka ─────────────────────────────────────────────
	Kafka KafkaConfig

	// ─── Business logic ────────────────────────────────────
	// Default hold TTL if the request doesn't specify one.
	DefaultHoldTTL time.Duration `envconfig:"INVENTORY_HOLD_TTL_SECONDS" default:"600s"`

	// Maximum additional seconds a single Extend call can add.
	MaxExtendSeconds int `envconfig:"INVENTORY_MAX_EXTEND_SECONDS" default:"300"`

	// How many seats a single HoldBestAvailable can return.
	MaxBestAvailableQuantity int `envconfig:"INVENTORY_MAX_BEST_AVAILABLE" default:"10"`
}

// RedisConfig holds connection details for the Redis cluster.
type RedisConfig struct {
	Host     string `envconfig:"REDIS_HOST" default:"redis"`
	Port     int    `envconfig:"REDIS_PORT" default:"6379"`
	Password string `envconfig:"REDIS_PASSWORD" default:""`
	DB       int    `envconfig:"REDIS_DB" default:"0"`

	// Connection pool tuning
	PoolSize     int           `envconfig:"REDIS_POOL_SIZE" default:"50"`
	MinIdleConns int           `envconfig:"REDIS_MIN_IDLE" default:"10"`
	DialTimeout  time.Duration `envconfig:"REDIS_DIAL_TIMEOUT" default:"5s"`
	ReadTimeout  time.Duration `envconfig:"REDIS_READ_TIMEOUT" default:"3s"`
	WriteTimeout time.Duration `envconfig:"REDIS_WRITE_TIMEOUT" default:"3s"`
}

// Addr returns the "host:port" string for dialing Redis.
func (r RedisConfig) Addr() string {
	return fmt.Sprintf("%s:%d", r.Host, r.Port)
}

// KafkaConfig holds broker and topic settings.
type KafkaConfig struct {
	Brokers     []string `envconfig:"KAFKA_BROKERS" default:"kafka:9092"`
	ClientID    string   `envconfig:"KAFKA_CLIENT_ID" default:"go-inventory"`
	GroupPrefix string   `envconfig:"KAFKA_GROUP_PREFIX" default:"omnitix"`

	// Topic prefix — matches our naming convention "omnitix.<aggregate>.<event>"
	TopicPrefix string `envconfig:"KAFKA_TOPIC_PREFIX" default:"omnitix"`
}

// Load reads config from the environment, optionally loading .env files
// first (for local development).
//
// File priority: `.env.local` wins over `.env`, so host-side overrides
// (127.0.0.1:6380, 127.0.0.1:29092) take precedence over the
// container-DNS defaults baked into the struct tags. Both files are
// optional — if neither exists (e.g. in Kubernetes where the
// orchestrator injects env vars directly), Load still succeeds.
func Load() (*Config, error) {
	// Load env files in priority order. `.env.local` wins over `.env`.
	// godotenv.Load is idempotent — an already-set OS env var is not
	// overwritten, and a missing file is a no-op (returns an error we
	// intentionally ignore).
	_ = godotenv.Load(".env.local", ".env")

	var cfg Config
	if err := envconfig.Process("", &cfg); err != nil {
		return nil, fmt.Errorf("load config: %w", err)
	}

	if err := cfg.Validate(); err != nil {
		return nil, fmt.Errorf("validate config: %w", err)
	}

	return &cfg, nil
}

// Validate performs semantic checks on loaded config.
func (c *Config) Validate() error {
	if c.GRPCPort <= 0 || c.GRPCPort > 65535 {
		return fmt.Errorf("invalid GRPCPort: %d", c.GRPCPort)
	}
	if c.HTTPPort <= 0 || c.HTTPPort > 65535 {
		return fmt.Errorf("invalid HTTPPort: %d", c.HTTPPort)
	}
	if c.DefaultHoldTTL < 30*time.Second {
		return fmt.Errorf("DefaultHoldTTL too short: %s", c.DefaultHoldTTL)
	}
	if len(c.Kafka.Brokers) == 0 {
		return fmt.Errorf("no Kafka brokers configured")
	}
	return nil
}

// IsProduction returns true if running in a production-like environment.
func (c *Config) IsProduction() bool {
	return c.Env == "production" || c.Env == "prod"
}