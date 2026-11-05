<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTierPricing extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'event_tier_pricing';

    protected $fillable = [
        'event_id', 'price_tier_id',
        'applicable_sections',
        'price_cents', 'currency',
        'service_fee_cents', 'facility_fee_cents', 'processing_fee_cents',
        'tax_rate', 'tax_inclusive',
        'sale_starts_at', 'sale_ends_at',
        'quantity_cap', 'display_order', 'is_active',
    ];

    protected $casts = [
        'applicable_sections'    => 'array',
        'price_cents'            => 'integer',
        'service_fee_cents'      => 'integer',
        'facility_fee_cents'     => 'integer',
        'processing_fee_cents'   => 'integer',
        'tax_rate'               => 'decimal:4',
        'sale_starts_at'         => 'datetime',
        'sale_ends_at'           => 'datetime',
        'quantity_cap'           => 'integer',
        'display_order'          => 'integer',
        'is_active'              => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'price_tier_id');
    }

    /**
     * Total price including all fees (pre-tax).
     */
    public function totalCents(): int
    {
        return $this->price_cents
             + $this->service_fee_cents
             + $this->facility_fee_cents
             + $this->processing_fee_cents;
    }
}