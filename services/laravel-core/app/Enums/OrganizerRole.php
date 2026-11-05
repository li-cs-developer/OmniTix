<?php

namespace App\Enums;

/**
 * Organizer-scoped roles and their permission sets.
 *
 * These are the roles a user can have *within an organizer's context*.
 * Distinct from `UserRole` (which is a user's global role).
 */
enum OrganizerRole: string
{
    case OWNER     = 'owner';
    case ADMIN     = 'admin';
    case BOX_OFFICE = 'box_office';
    case SCANNER   = 'scanner';
    case FINANCE   = 'finance';

    /**
     * Get the set of permissions for this role.
     *
     * @return Permission[]
     */
    public function permissions(): array
    {
        return match ($this) {
            self::OWNER => [
                // Owners can do everything
                Permission::EVENT_VIEW,
                Permission::EVENT_CREATE,
                Permission::EVENT_UPDATE,
                Permission::EVENT_DELETE,
                Permission::EVENT_PUBLISH,
                Permission::EVENT_CANCEL,
                Permission::VENUE_VIEW,
                Permission::VENUE_MANAGE,
                Permission::SEATMAP_VIEW,
                Permission::SEATMAP_MANAGE,
                Permission::PRICING_VIEW,
                Permission::PRICING_MANAGE,
                Permission::PROMO_VIEW,
                Permission::PROMO_MANAGE,
                Permission::SALES_VIEW,
                Permission::REPORT_VIEW,
                Permission::REPORT_EXPORT,
                Permission::ATTENDEE_VIEW,
                Permission::TICKET_SCAN,
                Permission::REFUND_VIEW,
                Permission::REFUND_ISSUE,
                Permission::PAYOUT_VIEW,
                Permission::PAYOUT_MANAGE,
                Permission::MEMBER_VIEW,
                Permission::MEMBER_MANAGE,
            ],
            self::ADMIN => [
                // Admins manage day-to-day operations but not payouts
                Permission::EVENT_VIEW,
                Permission::EVENT_CREATE,
                Permission::EVENT_UPDATE,
                Permission::EVENT_PUBLISH,
                Permission::EVENT_CANCEL,
                Permission::VENUE_VIEW,
                Permission::VENUE_MANAGE,
                Permission::SEATMAP_VIEW,
                Permission::SEATMAP_MANAGE,
                Permission::PRICING_VIEW,
                Permission::PRICING_MANAGE,
                Permission::PROMO_VIEW,
                Permission::PROMO_MANAGE,
                Permission::SALES_VIEW,
                Permission::REPORT_VIEW,
                Permission::REPORT_EXPORT,
                Permission::ATTENDEE_VIEW,
                Permission::TICKET_SCAN,
                Permission::REFUND_VIEW,
                Permission::REFUND_ISSUE,
                Permission::MEMBER_VIEW,
            ],
            self::BOX_OFFICE => [
                // Box office handles sales + scans + basic refunds
                Permission::EVENT_VIEW,
                Permission::VENUE_VIEW,
                Permission::SEATMAP_VIEW,
                Permission::PRICING_VIEW,
                Permission::SALES_VIEW,
                Permission::ATTENDEE_VIEW,
                Permission::TICKET_SCAN,
                Permission::REFUND_VIEW,
                Permission::REFUND_ISSUE,
            ],
            self::SCANNER => [
                // Scanners can only scan
                Permission::ATTENDEE_VIEW,
                Permission::TICKET_SCAN,
            ],
            self::FINANCE => [
                // Finance sees numbers, handles refunds, but can't touch events
                Permission::EVENT_VIEW,
                Permission::SALES_VIEW,
                Permission::REPORT_VIEW,
                Permission::REPORT_EXPORT,
                Permission::REFUND_VIEW,
                Permission::REFUND_ISSUE,
                Permission::PAYOUT_VIEW,
                Permission::PAYOUT_MANAGE,
            ],
        };
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}