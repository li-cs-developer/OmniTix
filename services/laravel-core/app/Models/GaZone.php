<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GaZone extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'ga_zones';

    protected $fillable = [
        'seat_map_id', 'venue_section_id',
        'name', 'code', 'capacity',
        'visual', 'is_active',
    ];

    protected $casts = [
        'visual'     => 'array',
        'capacity'   => 'integer',
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
}