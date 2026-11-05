<?php

namespace App\Kafka;

use Illuminate\Support\Facades\Log;
use longlang\phpkafka\Consumer\Consumer;
use longlang\phpkafka\Consumer\ConsumerConfig;
use longlang\phpkafka\Consumer\ConsumeMessage;

class KafkaConsumer
{
    private string $brokers;
    private string $groupId;

    public function __construct()
    {
        $this->brokers = env('KAFKA_BROKERS_HOST', 'kafka:9092');
        $this->groupId = env('KAFKA_CONSUMER_GROUP', 'omnitix-laravel-core');
    }

    /**
     * Consume a list of topics with a callback.
     *
     * @param array    $topics
     * @param callable $handler  function(string $topic, array $envelope): void
     */
    public function consume(array $topics, callable $handler): void
    {
        $config = new ConsumerConfig();
        $config->setBootstrapServer($this->brokers);
        $config->setGroupId($this->groupId);
        $config->setTopic($topics);              // <-- setTopic (singular), accepts array
        $config->setAutoCommit(0.5);             // seconds
        $config->setInterval(0.1);               // poll every 100ms

        // Signature: Consumer(ConsumerConfig $config, ?callable $consumeCallback = null)
        $consumer = new Consumer($config, function (ConsumeMessage $message) use ($handler) {
            $topic     = $message->getTopic();
            $payload   = $message->getValue();
            $partition = $message->getPartition();

            try {
                $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                Log::debug('Kafka event received', [
                    'topic'     => $topic,
                    'partition' => $partition,
                    'type'      => $envelope['type'] ?? null,
                ]);

                $handler($topic, $envelope);
            } catch (\Throwable $e) {
                Log::error('Kafka message handling failed', [
                    'topic'          => $topic,
                    'partition'      => $partition,
                    'error'          => $e->getMessage(),
                    'payload_prefix' => substr((string) $payload, 0, 200),
                ]);
            }
        });

        Log::info('Kafka consumer starting', [
            'topics'  => $topics,
            'group'   => $this->groupId,
            'brokers' => $this->brokers,
        ]);

        $consumer->start();  // <-- start() runs the internal consume loop
    }
}