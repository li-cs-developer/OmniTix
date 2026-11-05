<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeatMap extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'venue_id', 'version', 'name', 'notes',
        'width', 'height', 'meta', 'is_published',
    ];

    protected $casts = [
        'meta'         => 'array',
        'is_published' => 'boolean',
        'version'      => 'integer',
        'width'        => 'integer',
        'height'       => 'integer',
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

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}