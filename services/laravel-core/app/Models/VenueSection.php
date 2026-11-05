<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VenueSection extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'venue_id', 'name', 'code', 'kind', 'description',
        'visual', 'display_order', 'is_active',
    ];

    protected $casts = [
        'visual'         => 'array',
        'is_active'      => 'boolean',
        'display_order'  => 'integer',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function gaZones(): HasMany
    {
        return $this->hasMany(GaZone::class);
    }
}