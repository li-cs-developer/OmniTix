<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $ticket->ticket_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; padding: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #6c5ce7; padding-bottom: 20px; }
        .header h1 { font-size: 28px; color: #6c5ce7; margin-bottom: 6px; }
        .header p { font-size: 13px; color: #666; }
        .ticket { border: 1px solid #ddd; border-radius: 8px; padding: 30px; }
        .ticket h2 { font-size: 22px; margin-bottom: 16px; color: #6c5ce7; }
        .ticket dl { font-size: 14px; line-height: 1.8; margin-bottom: 24px; }
        .ticket dt { color: #888; display: inline-block; width: 130px; }
        .ticket dd { display: inline-block; color: #222; font-weight: 600; }
        .qr { text-align: center; margin-top: 20px; }
        .qr p { font-size: 11px; color: #666; margin-top: 8px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="header">
        <h1>OmniTix</h1>
        <p>{{ $event?->title ?? 'Event' }}</p>
    </div>

    <div class="ticket">
        <h2>{{ $tier?->name ?? 'Ticket' }}</h2>
        <dl>
            <dt>Ticket #</dt><dd>{{ $ticket->ticket_number }}</dd><br>
            @if($seat)
                <dt>Seat</dt><dd>{{ $seat->display_label }}</dd><br>
                <dt>Section</dt><dd>{{ $seat->section }}</dd><br>
                <dt>Row</dt><dd>{{ $seat->row_label }}</dd><br>
            @endif
            @if($occurrence)
                <dt>Doors</dt><dd>{{ $occurrence->starts_at?->format('D, M d, Y \a\t H:i') }}</dd><br>
            @endif
            <dt>Order</dt><dd>{{ $order?->reference }}</dd>
        </dl>

        <div class="qr">
            @if($qrSvg)
                <div style="width: 200px; height: 200px; margin: 0 auto;">{!! $qrSvg !!}</div>
                <p>{{ $ticket->ticket_number }}</p>
            @else
                <p style="color: #c00;">QR pending</p>
            @endif
        </div>
    </div>
</body>
</html>