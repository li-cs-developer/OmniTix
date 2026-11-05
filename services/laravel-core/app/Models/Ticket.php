<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'order_id', 'order_item_id',
        'event_id', 'event_occurrence_id',
        'seat_id', 'ga_zone_id', 'user_id',
        'ticket_number', 'price_tier_id',
        'qr_payload', 'qr_signature', 'signer_key_id', 'qr_issued_at',
        'status', 'scanned_at', 'scanned_by_user_id', 'scan_count',
        'is_refunded', 'refunded_at',
        'attendee_name', 'attendee_email',
        'metadata',
    ];

    protected $casts = [
        'qr_issued_at'      => 'datetime',
        'scanned_at'        => 'datetime',
        'refunded_at'       => 'datetime',
        'scan_count'        => 'integer',
        'is_refunded'       => 'boolean',
        'metadata'          => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->ticket_number)) {
                // Format: OTX-XXXXXXXXXXXX (16 chars of entropy)
                $ticket->ticket_number = 'OTX-' . strtoupper(Str::random(12));
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function gaZone(): BelongsTo
    {
        return $this->belongsTo(GaZone::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class);
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }
}