<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organizer_id', 'venue_id', 'seat_map_id',
        'title', 'slug', 'description', 'short_description',
        'hero_image_url', 'gallery',
        'category', 'tags',
        'performer_name', 'performer_bio',
        'age_restriction',
        'sales_start_at', 'sales_end_at',
        'status',
        'max_tickets_per_user', 'max_tickets_per_card',
        'refund_cutoff_hours', 'allow_refunds_after_start',
        'settings',
    ];

    protected $casts = [
        'gallery'                   => 'array',
        'tags'                      => 'array',
        'settings'                  => 'array',
        'sales_start_at'            => 'datetime',
        'sales_end_at'              => 'datetime',
        'allow_refunds_after_start' => 'boolean',
        'age_restriction'           => 'integer',
        'max_tickets_per_user'      => 'integer',
        'max_tickets_per_card'      => 'integer',
        'refund_cutoff_hours'       => 'integer',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function seatMap(): BelongsTo
    {
        return $this->belongsTo(SeatMap::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    public function tierPricing(): HasMany
    {
        return $this->hasMany(EventTierPricing::class);
    }
}