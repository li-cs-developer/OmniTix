<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $order->reference }} — Tickets</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; padding: 30px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #6c5ce7; padding-bottom: 20px; }
        .header h1 { font-size: 28px; color: #6c5ce7; margin-bottom: 6px; }
        .header p { font-size: 13px; color: #666; }
        .order-info { font-size: 13px; color: #555; margin-bottom: 24px; }
        .order-info span { display: inline-block; margin-right: 20px; }
        .order-info strong { color: #222; }
        .ticket {
            page-break-inside: avoid;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
        }
        .ticket-info { flex: 1; }
        .ticket-info h2 { font-size: 18px; margin-bottom: 10px; color: #6c5ce7; }
        .ticket-info dl { font-size: 13px; line-height: 1.7; }
        .ticket-info dt { color: #888; display: inline-block; width: 120px; }
        .ticket-info dd { display: inline-block; color: #222; font-weight: 600; }
        .ticket-qr { text-align: center; min-width: 200px; }
        .ticket-qr p { font-size: 11px; color: #666; margin-top: 8px; font-family: monospace; }
        .footer { margin-top: 40px; text-align: center; font-size: 11px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>OmniTix</h1>
        <p>{{ $order->event->title ?? 'Event' }}</p>
    </div>

    <div class="order-info">
        <span><strong>Order:</strong> {{ $order->reference }}</span>
        @if($order->paid_at)
            <span><strong>Paid:</strong> {{ $order->paid_at->format('M d, Y H:i') }}</span>
        @endif
        @if($order->event?->venue)
            <span><strong>Venue:</strong> {{ $order->event->venue->name }}</span>
        @endif
    </div>

    @foreach($tickets as $item)
        @php $t = $item['ticket']; @endphp
        <div class="ticket">
            <div class="ticket-info">
                <h2>{{ $t->priceTier?->name ?? 'Ticket' }}</h2>
                <dl>
                    <dt>Ticket #</dt><dd>{{ $t->ticket_number }}</dd><br>
                    @if($t->seat)
                        <dt>Seat</dt><dd>{{ $t->seat->display_label }}</dd><br>
                        <dt>Section</dt><dd>{{ $t->seat->section }}</dd><br>
                        <dt>Row</dt><dd>{{ $t->seat->row_label }}</dd><br>
                    @endif
                    @if($t->occurrence)
                        <dt>Doors</dt>
                        <dd>{{ $t->occurrence->starts_at?->format('D, M d, Y \a\t H:i') }}</dd><br>
                    @endif
                    <dt>Attendee</dt><dd>{{ $t->attendee_name ?? $order->guest_email ?? 'Guest' }}</dd>
                </dl>
            </div>
            <div class="ticket-qr">
                @if($item['qrSvg'])
                    <div style="width: 180px; height: 180px; margin: 0 auto;">{!! $item['qrSvg'] !!}</div>
                    <p>{{ $t->ticket_number }}</p>
                @else
                    <p style="color: #c00;">QR pending</p>
                @endif
            </div>
        </div>
    @endforeach

    <div class="footer">
        <p>Present this QR code at the gate. One scan per ticket. Non-transferable.</p>
        <p>OmniTix — All-in-one ticketing.</p>
    </div>
</body>
</html>