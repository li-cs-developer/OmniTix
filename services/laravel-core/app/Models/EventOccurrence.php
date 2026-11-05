<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventOccurrence extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'event_id', 'starts_at', 'ends_at', 'doors_open_at', 'label',
        'capacity_total', 'capacity_sold', 'capacity_held',
        'sales_start_at', 'sales_end_at', 'status', 'settings',
    ];

    protected $casts = [
        'starts_at'       => 'datetime',
        'ends_at'         => 'datetime',
        'doors_open_at'   => 'datetime',
        'sales_start_at'  => 'datetime',
        'sales_end_at'    => 'datetime',
        'settings'        => 'array',
        'capacity_total'  => 'integer',
        'capacity_sold'   => 'integer',
        'capacity_held'   => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function availableCapacity(): int
    {
        return max(0, $this->capacity_total - $this->capacity_sold - $this->capacity_held);
    }
}