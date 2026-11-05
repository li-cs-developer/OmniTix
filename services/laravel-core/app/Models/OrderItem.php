<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'order_id', 'seat_id', 'ga_zone_id', 'quantity', 'price_tier_id',
        'unit_price_cents', 'service_fee_cents', 'facility_fee_cents',
        'processing_fee_cents', 'line_subtotal_cents', 'line_total_cents',
        'line_discount_cents',
    ];

    protected $casts = [
        'quantity'             => 'integer',
        'unit_price_cents'     => 'integer',
        'service_fee_cents'    => 'integer',
        'facility_fee_cents'   => 'integer',
        'processing_fee_cents' => 'integer',
        'line_subtotal_cents'  => 'integer',
        'line_total_cents'     => 'integer',
        'line_discount_cents'  => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function gaZone(): BelongsTo
    {
        return $this->belongsTo(GaZone::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}