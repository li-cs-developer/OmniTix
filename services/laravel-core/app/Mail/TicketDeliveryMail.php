<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketDeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $orderReference,
        public string $eventTitle,
        public string $buyerName,
        public int $ticketCount,
        public ?string $startsAt,
        public string $pdfBytes,
        public string $pdfFilename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your tickets for {$this->eventTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket',
            with: [
                'orderReference' => $this->orderReference,
                'eventTitle'     => $this->eventTitle,
                'buyerName'      => $this->buyerName,
                'ticketCount'    => $this->ticketCount,
                'startsAt'       => $this->startsAt,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }
}