<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'organizer_id',
        'email', 'password',
        'first_name', 'last_name', 'phone', 'locale', 'timezone',
        'role',
        'is_banned', 'ban_reason', 'last_login_at',
        'settings',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'role'              => UserRole::class,
        'is_banned'         => 'boolean',
        'settings'          => 'array',
        'password'          => 'hashed',
    ];

    // ─── Relationships ─────────────────────────────────
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizerMember::class);
    }

    // ─── Helpers ───────────────────────────────────────
    public function isStaff(): bool
    {
        return $this->role instanceof UserRole && $this->role->isStaff();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }
}