<?php

namespace App\Support;

use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;

/**
 * Thin wrapper around longlang/phpkafka's producer.
 *
 * Kept deliberately small so we can swap the underlying client later
 * without touching call sites.
 */
class KafkaClient
{
    private ?Producer $producer = null;

    public function __construct(
        private readonly string $brokers,
        private readonly string $clientId = 'omnitix-laravel-core',
    ) {}

    public function send(string $topic, string $key, string $value): void
    {
        if ($this->producer === null) {
            $config = new ProducerConfig();
            $config->setBootstrapServer($this->brokers);
            $config->setAcks(-1);         // all
            $config->setTimeout(5.0);
            $this->producer = new Producer($config);
        }

        $this->producer->send($topic, $value, $key);
    }
}