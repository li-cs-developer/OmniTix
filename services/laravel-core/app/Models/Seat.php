<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Seat extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'seat_map_id', 'venue_section_id',
        'section', 'row_label', 'seat_number', 'display_label',
        'x', 'y', 'rotation',
        'attributes', 'meta', 'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'meta'       => 'array',
        'x'          => 'decimal:2',
        'y'          => 'decimal:2',
        'rotation'   => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function seatMap(): BelongsTo
    {
        return $this->belongsTo(SeatMap::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(VenueSection::class, 'venue_section_id');
    }

    // Convenience scope
    public function scopeWheelchair($q)
    {
        return $q->where('attributes->wheelchair', true);
    }
}