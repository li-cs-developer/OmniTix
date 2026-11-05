<?php

namespace App\Enums;

enum UserRole: string
{
    case Buyer          = 'buyer';
    case OrganizerOwner = 'organizer_owner';
    case OrganizerAdmin = 'organizer_admin';
    case BoxOffice      = 'box_office';
    case Scanner        = 'scanner';
    case Finance        = 'finance';
    case SuperAdmin     = 'super_admin';

    public function isStaff(): bool
    {
        return $this !== self::Buyer;
    }
}