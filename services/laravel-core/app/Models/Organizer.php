<?php

namespace App\Models;

use App\Enums\OrganizerKycStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organizer extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'website', 'description', 'logo_url',
        'legal_name', 'tax_id', 'country', 'currency', 'timezone',
        'kyc_status', 'kyc_verified_at', 'kyc_notes',
        'payout_schedule', 'holdback_days', 'stripe_account_id',
        'settings',
    ];

    protected $casts = [
        'kyc_status'       => OrganizerKycStatus::class,
        'kyc_verified_at'  => 'datetime',
        'settings'         => 'array',
        'holdback_days'    => 'integer',
    ];

    // ─── Relationships ─────────────────────────────────
    public function members(): HasMany
    {
        return $this->hasMany(OrganizerMember::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}