// Package kafka wraps franz-go for producing OmniTix events.
package kafka

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/omnitix/go-inventory/internal/config"
	"github.com/omnitix/go-inventory/internal/logger"
	"github.com/oklog/ulid/v2"
	"github.com/twmb/franz-go/pkg/kgo"
	"go.uber.org/zap"
	"crypto/rand"
)

// Producer emits structured events to Kafka.
type Producer struct {
	client  *kgo.Client
	prefix  string
	producerName string
	log     *logger.Logger
}

// NewProducer creates a Kafka producer. The client connects lazily on first
// produce, so this returns immediately even if Kafka is unreachable.
func NewProducer(cfg *config.KafkaConfig, log *logger.Logger, producerName string) (*Producer, error) {
	opts := []kgo.Opt{
		kgo.SeedBrokers(cfg.Brokers...),
		kgo.ClientID(cfg.ClientID),

		// Wait for all replicas to ack (single-broker dev still works).
		kgo.RequiredAcks(kgo.AllISRAcks()),

		// Idempotent producer: exactly-once semantics within a session.
		kgo.ProducerBatchCompression(kgo.GzipCompression()),

		// Small linger for batching without adding perceptible latency.
		kgo.ProducerLinger(5 * time.Millisecond),

		// Bound memory: fail if we can't produce within 100ms of batching.
		kgo.ProducerBatchMaxBytes(1_000_000), // 1 MB
		kgo.MaxBufferedRecords(10_000),
		kgo.RecordRetries(3),
		kgo.RecordDeliveryTimeout(10 * time.Second),

		// Allow auto-topic-creation (dev convenience; disable in prod).
		kgo.AllowAutoTopicCreation(),
	}

	client, err := kgo.NewClient(opts...)
	if err != nil {
		return nil, fmt.Errorf("kafka client: %w", err)
	}

	return &Producer{
		client:       client,
		prefix:       cfg.TopicPrefix,
		producerName: producerName,
		log:          log.Named("kafka"),
	}, nil
}

// WaitUntilReady pings the brokers and returns when reachable.
func (p *Producer) WaitUntilReady(ctx context.Context, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for {
		if time.Now().After(deadline) {
			return fmt.Errorf("kafka not ready after %s", timeout)
		}

		pingCtx, cancel := context.WithTimeout(ctx, 3*time.Second)
		err := p.client.Ping(pingCtx)
		cancel()

		if err == nil {
			p.log.Info("kafka is ready")
			return nil
		}

		p.log.Debug("kafka not ready yet, retrying", zap.Error(err))

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
}

// Envelope is the standard OmniTix event wrapper.
type Envelope struct {
	ID            string          `json:"id"`
	Type          string          `json:"type"`
	Version       int             `json:"version"`
	OccurredAt    string          `json:"occurred_at"`
	Producer      string          `json:"producer"`
	CorrelationID string          `json:"correlation_id,omitempty"`
	PartitionKey  string          `json:"partition_key,omitempty"`
	Payload       json.RawMessage `json:"payload"`
}

// Publish wraps a payload in an Envelope and sends it to the given topic suffix.
//
// topicSuffix: "seat.held", "ga.sold", etc. — the full topic will be
// "<prefix>.<topicSuffix>".
//
// partitionKey: the message will be routed to the partition determined by
// this key. All events with the same key go to the same partition.
func (p *Producer) Publish(
	ctx context.Context,
	topicSuffix string,
	partitionKey string,
	correlationID string,
	payload any,
) error {
	payloadBytes, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("marshal payload: %w", err)
	}

	env := Envelope{
		ID:            newEventID(),
		Type:          topicSuffix,
		Version:       1,
		OccurredAt:    time.Now().UTC().Format(time.RFC3339Nano),
		Producer:      p.producerName,
		CorrelationID: correlationID,
		PartitionKey:  partitionKey,
		Payload:       payloadBytes,
	}

	envBytes, err := json.Marshal(env)
	if err != nil {
		return fmt.Errorf("marshal envelope: %w", err)
	}

	fullTopic := p.prefix + "." + topicSuffix
	record := &kgo.Record{
		Topic: fullTopic,
		Key:   []byte(partitionKey),
		Value: envBytes,
	}

	// Fire-and-forget in v1: run in a goroutine, log errors. Never block
	// the caller on Kafka.
	go func() {
		// Use a background context with a timeout so a slow Kafka doesn't
		// hold resources forever.
		produceCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()

		result := p.client.ProduceSync(produceCtx, record)
		if err := result.FirstErr(); err != nil {
			p.log.Error("kafka produce failed",
				zap.String("topic", fullTopic),
				zap.String("event_id", env.ID),
				zap.String("event_type", env.Type),
				zap.Error(err),
			)
			return
		}

		p.log.Debug("kafka event produced",
			zap.String("topic", fullTopic),
			zap.String("event_id", env.ID),
			zap.String("event_type", env.Type),
			zap.String("partition_key", partitionKey),
		)
	}()

	return nil
}

// Close flushes pending records and closes the client.
func (p *Producer) Close() {
	p.client.Close()
}

// newEventID returns a ULID suitable for use as a Kafka event ID.
func newEventID() string {
	entropy := ulid.Monotonic(rand.Reader, 0)
	return ulid.MustNew(ulid.Timestamp(time.Now()), entropy).String()
}