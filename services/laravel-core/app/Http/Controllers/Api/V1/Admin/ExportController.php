<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /** GET /api/v1/admin/events/{id}/export/sales.csv */
    public function salesCsv(Request $request, string $eventId): StreamedResponse
    {
        $membership = $request->attributes->get('organizer_membership');
        $event = Event::where('id', $eventId)
            ->where('organizer_id', $membership->organizer_id)
            ->firstOrFail();

        return $this->streamCsv("sales-{$event->slug}.csv", function ($out) use ($event) {
            fputcsv($out, [
                'occurrence_id', 'occurrence_starts_at', 'order_reference', 'buyer_email',
                'status', 'subtotal_cents', 'discount_cents', 'fees_cents', 'tax_cents',
                'total_cents', 'currency', 'paid_at',
            ]);

            Order::query()
                ->where('event_id', $event->id)
                ->whereIn('status', ['fulfilled', 'refunded', 'partially_refunded'])
                ->orderBy('paid_at')
                ->chunk(1000, function ($orders) use ($out) {
                    foreach ($orders as $o) {
                        fputcsv($out, [
                            $o->event_occurrence_id,
                            $o->occurrence?->starts_at?->toIso8601String(),
                            $o->reference,
                            $o->guest_email ?? $o->user?->email,
                            $o->status,
                            $o->subtotal_cents,
                            $o->discount_cents,
                            $o->service_fee_cents + $o->facility_fee_cents + $o->processing_fee_cents,
                            $o->tax_cents,
                            $o->total_cents,
                            $o->currency,
                            $o->paid_at?->toIso8601String(),
                        ]);
                    }
                });
        });
    }

    /** GET /api/v1/admin/events/{id}/export/attendees.csv */
    public function attendeesCsv(Request $request, string $eventId): StreamedResponse
    {
        $membership = $request->attributes->get('organizer_membership');
        $event = Event::where('id', $eventId)
            ->where('organizer_id', $membership->organizer_id)
            ->firstOrFail();

        return $this->streamCsv("attendees-{$event->slug}.csv", function ($out) use ($event) {
            fputcsv($out, [
                'ticket_number', 'attendee_name', 'attendee_email', 'seat_label', 'section',
                'row', 'seat_number', 'tier_name', 'status', 'scanned_at', 'scan_count',
            ]);

            Ticket::query()
                ->where('event_id', $event->id)
                ->with(['seat', 'priceTier', 'order'])
                ->orderBy('created_at')
                ->chunk(1000, function ($tickets) use ($out) {
                    foreach ($tickets as $t) {
                        fputcsv($out, [
                            $t->ticket_number,
                            $t->attendee_name,
                            $t->attendee_email ?? $t->order?->guest_email,
                            $t->seat?->display_label,
                            $t->seat?->section,
                            $t->seat?->row_label,
                            $t->seat?->seat_number,
                            $t->priceTier?->name,
                            $t->status,
                            $t->scanned_at?->toIso8601String(),
                            $t->scan_count,
                        ]);
                    }
                });
        });
    }

    /** GET /api/v1/admin/events/{id}/export/tax.csv */
    public function taxCsv(Request $request, string $eventId): StreamedResponse
    {
        $membership = $request->attributes->get('organizer_membership');
        $event = Event::where('id', $eventId)
            ->where('organizer_id', $membership->organizer_id)
            ->firstOrFail();

        // Group by month + tier for tax filing.
        $rows = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('price_tiers', 'price_tiers.id', '=', 'order_items.price_tier_id')
            ->where('orders.event_id', $event->id)
            ->whereIn('orders.status', ['fulfilled', 'partially_refunded'])
            ->selectRaw("
                to_char(orders.paid_at, 'YYYY-MM') AS tax_month,
                price_tiers.name AS tier_name,
                orders.currency,
                SUM(order_items.line_subtotal_cents) AS subtotal_cents,
                SUM(order_items.line_total_cents) AS total_cents,
                COUNT(*) AS item_count
            ")
            ->groupBy('tax_month', 'tier_name', 'orders.currency')
            ->orderBy('tax_month')
            ->get();

        return $this->streamCsv("tax-{$event->slug}.csv", function ($out) use ($rows) {
            fputcsv($out, ['tax_month', 'tier_name', 'currency', 'subtotal_cents', 'total_cents', 'item_count']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->tax_month, $r->tier_name, $r->currency,
                    $r->subtotal_cents, $r->total_cents, $r->item_count,
                ]);
            }
        });
    }

    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}