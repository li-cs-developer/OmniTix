<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceTier extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organizer_id', 'name', 'code', 'description',
        'min_age', 'max_age', 'color', 'display_order', 'is_active',
    ];

    protected $casts = [
        'min_age'       => 'integer',
        'max_age'       => 'integer',
        'display_order' => 'integer',
        'is_active'     => 'boolean',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function eventPricing(): HasMany
    {
        return $this->hasMany(EventTierPricing::class);
    }
}