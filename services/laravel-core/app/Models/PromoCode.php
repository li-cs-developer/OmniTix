<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organizer_id', 'event_id', 'code', 'description',
        'discount_type', 'discount_value', 'max_discount_cents',
        'max_redemptions', 'max_redemptions_per_user', 'redemptions_count',
        'applicable_sections', 'applicable_tier_ids',
        'min_order_cents',
        'starts_at', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'applicable_sections'        => 'array',
        'applicable_tier_ids'        => 'array',
        'discount_value'             => 'integer',
        'max_discount_cents'         => 'integer',
        'max_redemptions'            => 'integer',
        'max_redemptions_per_user'   => 'integer',
        'redemptions_count'          => 'integer',
        'min_order_cents'            => 'integer',
        'starts_at'                  => 'datetime',
        'expires_at'                 => 'datetime',
        'is_active'                  => 'boolean',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }
}