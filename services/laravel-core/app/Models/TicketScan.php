<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketScan extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'ticket_id', 'scanned_by_user_id', 'gate_identifier',
        'result', 'scanner_ip', 'scanner_user_agent', 'context',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'context'    => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }
}