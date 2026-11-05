<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'reference',
        'user_id', 'guest_email', 'guest_phone',
        'event_id', 'event_occurrence_id', 'promo_code_id',
        'subtotal_cents', 'discount_cents',
        'service_fee_cents', 'facility_fee_cents', 'processing_fee_cents',
        'tax_cents', 'total_cents', 'currency',
        'status', 'hold_expires_at',
        'paid_at', 'fulfilled_at', 'cancelled_at', 'refunded_at',
        'idempotency_key', 'ip_address', 'user_agent', 'metadata',
        'payment_id', 'provider_ref', 'payment_captured_at',
    ];

    protected $casts = [
        'subtotal_cents'       => 'integer',
        'discount_cents'       => 'integer',
        'service_fee_cents'    => 'integer',
        'facility_fee_cents'   => 'integer',
        'processing_fee_cents' => 'integer',
        'tax_cents'            => 'integer',
        'total_cents'          => 'integer',
        'hold_expires_at'      => 'datetime',
        'paid_at'              => 'datetime',
        'fulfilled_at'         => 'datetime',
        'cancelled_at'         => 'datetime',
        'refunded_at'          => 'datetime',
        'payment_captured_at'  => 'datetime',
        'metadata'             => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->reference)) {
                $order->reference = 'OTX-' . strtoupper(Str::random(8));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}