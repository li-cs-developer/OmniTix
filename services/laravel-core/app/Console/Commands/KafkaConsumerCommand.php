<?php

namespace App\Console\Commands;

use App\Kafka\Handlers\PaymentCompletedHandler;
use App\Kafka\Handlers\RefundIssuedHandler;
use App\Kafka\Handlers\TicketScannedHandler;
use App\Kafka\Handlers\SeatHeldHandler;
use App\Kafka\Handlers\SeatExpiredHandler;
use App\Kafka\Handlers\SeatSoldHandler;
use App\Kafka\Handlers\GaHeldHandler;
use App\Kafka\Handlers\GaExpiredHandler;
use App\Kafka\Handlers\GaSoldHandler;
use App\Kafka\KafkaConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class KafkaConsumerCommand extends Command
{
    protected $signature = 'omnitix:kafka-consume';
    protected $description = 'Consume OmniTix Kafka events';

    public function handle(
        KafkaConsumer $consumer,
        PaymentCompletedHandler $paymentHandler,
        RefundIssuedHandler $refundHandler,
        TicketScannedHandler $ticketScannedHandler,
        SeatHeldHandler $seatHeld,
        SeatExpiredHandler $seatExpired,
        SeatSoldHandler $seatSold,
        GaHeldHandler $gaHeld,
        GaExpiredHandler $gaExpired,
        GaSoldHandler $gaSold,
    ): int {
        $topics = [
            'omnitix.payment.completed',
            'omnitix.payment.failed',
            'omnitix.refund.issued',
            'omnitix.ticket.scanned',
            'omnitix.seat.held',
            'omnitix.seat.expired',
            'omnitix.seat.sold',
            'omnitix.ga.held',
            'omnitix.ga.expired',
            'omnitix.ga.sold',
        ];

        $this->info('Starting Kafka consumer...');
        $this->info('Topics: ' . implode(', ', $topics));

        $consumer->consume($topics, function (string $topic, array $envelope) use (
            $paymentHandler, $refundHandler, $ticketScannedHandler,
            $seatHeld, $seatExpired, $seatSold,
            $gaHeld, $gaExpired, $gaSold,
        ) {
            $type    = $envelope['type'] ?? null;
            $payload = $envelope['payload'] ?? [];

            match ($type) {
                'payment.completed' => $paymentHandler->handle($payload),
                'payment.failed'    => null,
                'refund.issued'     => $refundHandler->handle($payload),
                'ticket.scanned'    => $ticketScannedHandler->handle($payload),
                'seat.held'         => $seatHeld->handle($payload),
                'seat.expired'      => $seatExpired->handle($payload),
                'seat.sold'         => $seatSold->handle($payload),
                'ga.held'           => $gaHeld->handle($payload),
                'ga.expired'        => $gaExpired->handle($payload),
                'ga.sold'           => $gaSold->handle($payload),
                default             => Log::debug('Unhandled Kafka event type', ['type' => $type]),
            };
        });

        return self::SUCCESS;
    }
}