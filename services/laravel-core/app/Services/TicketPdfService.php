<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

class TicketPdfService
{
    /**
     * Render a QR string as raw SVG markup.
     */
    private function qrSvgMarkup(string $qrString, int $size = 200): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd(),
        );

        $writer = new Writer($renderer);

        return $writer->writeString($qrString);
    }

    /**
     * Build the full QR string for a ticket: payload.signature.key_id
     */
    private function qrStringForTicket(Ticket $ticket): ?string
    {
        if (! $ticket->qr_payload || ! $ticket->qr_signature || ! $ticket->signer_key_id) {
            return null;
        }

        return "{$ticket->qr_payload}.{$ticket->qr_signature}.{$ticket->signer_key_id}";
    }

    /**
     * Generate a PDF for a single ticket. Returns the S3 key path.
     */
    public function generateForTicket(Ticket $ticket): string
    {
        $ticket->load(['seat', 'priceTier', 'event', 'occurrence', 'order']);

        $qrString = $this->qrStringForTicket($ticket);
        $qrSvg = $qrString ? $this->qrSvgMarkup($qrString, 200) : null;

        $pdf = Pdf::loadView('tickets.single-pdf', [
            'ticket'     => $ticket,
            'order'      => $ticket->order,
            'event'      => $ticket->event,
            'occurrence' => $ticket->occurrence,
            'seat'       => $ticket->seat,
            'tier'       => $ticket->priceTier,
            'qrSvg'      => $qrSvg,
            'qrString'   => $qrString,
        ])
        ->setPaper('A4', 'portrait')
        ->setOptions([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ]);

        $key = "tickets/{$ticket->event_id}/{$ticket->ticket_number}.pdf";

        Storage::disk('s3')->put($key, $pdf->output(), 'private');

        return $key;
    }

    /**
     * Generate a single PDF containing all tickets for an order.
     * Returns the S3 key path.
     */
    public function generateForOrder(Order $order): string
    {
        $order->load([
            'tickets.seat',
            'tickets.priceTier',
            'tickets.event',
            'tickets.occurrence',
        ]);

        $tickets = $order->tickets->map(function ($ticket) {
            $qrString = $this->qrStringForTicket($ticket);
            $qrSvg = $qrString ? $this->qrSvgMarkup($qrString, 180) : null;

            return [
                'ticket'   => $ticket,
                'qrSvg'    => $qrSvg,
                'qrString' => $qrString,
            ];
        });

        $pdf = Pdf::loadView('tickets.order-pdf', [
            'order'   => $order,
            'tickets' => $tickets,
        ])
        ->setPaper('A4', 'portrait')
        ->setOptions([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ]);

        $key = "orders/{$order->id}/{$order->reference}.pdf";

        Storage::disk('s3')->put($key, $pdf->output(), 'private');

        return $key;
    }

    /**
     * Generate a temporary URL for a ticket PDF.
     */
    public function temporaryUrl(string $key, int $minutes = 60): string
    {
        return Storage::disk('s3')->temporaryUrl($key, now()->addMinutes($minutes));
    }
}