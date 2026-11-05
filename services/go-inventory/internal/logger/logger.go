// Package logger provides a structured logger for all OmniTix Go services.
// Wraps zap with service-specific fields and level-based output formatting.
package logger

import (
	"os"

	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
)

// Logger wraps *zap.Logger with a service name for consistent field tagging.
type Logger struct {
	*zap.Logger
}

// New creates a Logger configured for the given environment and level.
//
// In development (env=local), output is human-readable with colors.
// In production, output is JSON for log aggregators.
func New(service, env, level string) (*Logger, error) {
	zapLevel, err := zapcore.ParseLevel(level)
	if err != nil {
		zapLevel = zapcore.InfoLevel
	}

	var cfg zap.Config
	if env == "production" || env == "prod" {
		cfg = zap.NewProductionConfig()
		cfg.Encoding = "json"
	} else {
		cfg = zap.NewDevelopmentConfig()
		cfg.Encoding = "console"
		cfg.EncoderConfig.EncodeLevel = zapcore.CapitalColorLevelEncoder
	}

	cfg.Level = zap.NewAtomicLevelAt(zapLevel)
	cfg.OutputPaths = []string{"stdout"}
	cfg.ErrorOutputPaths = []string{"stderr"}

	base, err := cfg.Build(
		zap.AddCallerSkip(1),
		zap.AddStacktrace(zapcore.ErrorLevel),
	)
	if err != nil {
		return nil, err
	}

	base = base.With(
		zap.String("service", service),
		zap.String("env", env),
	)

	return &Logger{Logger: base}, nil
}

// MustNew panics if logger creation fails. Useful in main().
func MustNew(service, env, level string) *Logger {
	log, err := New(service, env, level)
	if err != nil {
		// Can't even log the error properly, so just print + exit.
		os.Stderr.WriteString("failed to initialize logger: " + err.Error() + "\n")
		os.Exit(1)
	}
	return log
}

// With returns a child logger with additional structured fields.
func (l *Logger) With(fields ...zap.Field) *Logger {
	return &Logger{Logger: l.Logger.With(fields...)}
}

// Named returns a child logger with a subsystem name appended.
func (l *Logger) Named(name string) *Logger {
	return &Logger{Logger: l.Logger.Named(name)}
}

// Sync flushes any buffered log entries. Call in a defer from main().
func (l *Logger) Sync() error {
	return l.Logger.Sync()
}