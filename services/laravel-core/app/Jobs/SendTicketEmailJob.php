<?php

namespace App\Jobs;

use App\Mail\TicketDeliveryMail;
use App\Models\Order;
use App\Services\TicketPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SendTicketEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public string $orderId,
    ) {}

    public function handle(TicketPdfService $pdfService): void
    {
        $order = Order::with([
            'tickets.seat',
            'tickets.priceTier',
            'event.venue',
            'occurrence',
            'user',
        ])->find($this->orderId);

        if (! $order) {
            Log::warning('SendTicketEmailJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        if ($order->tickets->isEmpty()) {
            Log::warning('SendTicketEmailJob: no tickets on order', ['order_id' => $this->orderId]);
            return;
        }

        $buyerEmail = $order->guest_email ?? $order->user?->email;
        if (! $buyerEmail) {
            Log::warning('SendTicketEmailJob: no buyer email', ['order_id' => $this->orderId]);
            return;
        }

        // Generate the combined PDF for the order and upload to S3
        $s3Key = $pdfService->generateForOrder($order);

        // Read it back from S3 to attach it
        $pdfBytes = Storage::disk('s3')->get($s3Key);
        $filename = "{$order->reference}.pdf";

        $buyerName = $order->user?->first_name ?? 'there';
        $startsAt = $order->occurrence?->starts_at?->format('D, M d, Y \a\t g:i A');

        Mail::to($buyerEmail)->send(new TicketDeliveryMail(
            orderReference: $order->reference,
            eventTitle:     $order->event->title ?? 'Event',
            buyerName:      $buyerName,
            ticketCount:    $order->tickets->count(),
            startsAt:       $startsAt,
            pdfBytes:       $pdfBytes,
            pdfFilename:    $filename,
        ));

        Log::info('Ticket email sent', [
            'order_id' => $order->id,
            'to'       => $buyerEmail,
            'pdf_key'  => $s3Key,
        ]);
    }
}