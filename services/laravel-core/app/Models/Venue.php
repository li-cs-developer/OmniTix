<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organizer_id', 'name', 'slug', 'description',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'latitude', 'longitude',
        'phone', 'email', 'website',
        'timezone', 'total_capacity', 'settings',
    ];

    protected $casts = [
        'latitude'       => 'decimal:7',
        'longitude'      => 'decimal:7',
        'settings'       => 'array',
        'total_capacity' => 'integer',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(VenueSection::class);
    }

    public function seatMaps(): HasMany
    {
        return $this->hasMany(SeatMap::class);
    }

    public function publishedSeatMap(): ?SeatMap
    {
        return $this->seatMaps()->where('is_published', true)->latest('version')->first();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}