<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your OmniTix tickets</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #222; padding: 40px; background: #f5f5f7; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #6c5ce7, #8b5cf6); color: white; padding: 32px; text-align: center; }
        .header h1 { font-size: 24px; margin-bottom: 8px; }
        .body { padding: 32px; }
        .body h2 { font-size: 20px; margin-bottom: 16px; color: #222; }
        .body p { line-height: 1.6; color: #555; margin-bottom: 16px; }
        .order-card { background: #f8f8fa; border-radius: 8px; padding: 20px; margin: 24px 0; }
        .order-card dl { font-size: 14px; line-height: 1.8; }
        .order-card dt { color: #888; display: inline-block; width: 100px; }
        .order-card dd { display: inline-block; color: #222; font-weight: 600; }
        .footer { text-align: center; padding: 24px; font-size: 12px; color: #999; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>OmniTix</h1>
            <p>Your tickets are ready</p>
        </div>

        <div class="body">
            <h2>Hi {{ $buyerName ?? 'there' }},</h2>
            <p>Thanks for your purchase. Your tickets for <strong>{{ $eventTitle }}</strong> are attached below as a PDF.</p>

            <div class="order-card">
                <dl>
                    <dt>Order</dt><dd>{{ $orderReference }}</dd><br>
                    <dt>Event</dt><dd>{{ $eventTitle }}</dd><br>
                    <dt>Tickets</dt><dd>{{ $ticketCount }}</dd><br>
                    @if($startsAt)
                        <dt>Showtime</dt><dd>{{ $startsAt }}</dd><br>
                    @endif
                </dl>
            </div>

            <p>Present the QR code in the PDF at the gate. Each ticket can only be scanned once.</p>
            <p><em>Tip: save the PDF to your phone before you arrive — the QR works offline.</em></p>
        </div>

        <div class="footer">
            © {{ date('Y') }} OmniTix. If you didn't make this purchase, please contact support.
        </div>
    </div>
</body>
</html>