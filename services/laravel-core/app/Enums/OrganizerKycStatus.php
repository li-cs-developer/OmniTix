<?php

namespace App\Enums;

enum OrganizerKycStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}