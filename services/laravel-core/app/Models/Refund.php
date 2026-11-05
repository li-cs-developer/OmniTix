<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'order_id', 'payment_id', 'issued_by_user_id',
        'amount_cents', 'currency', 'reason',
        'provider_ref', 'affected_ticket_ids',
        'status', 'failure_code', 'failure_message',
        'processed_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'affected_ticket_ids' => 'array',
        'processed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}