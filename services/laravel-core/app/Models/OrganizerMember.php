<?php

namespace App\Models;

use App\Enums\OrganizerRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerMember extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organizer_id', 'user_id', 'role',
        'invited_by_id', 'accepted_at', 'revoked_at',
    ];

    protected $casts = [
        'role'        => OrganizerRole::class,
        'accepted_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }
}