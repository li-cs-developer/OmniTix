<?php

namespace App\Enums;

/**
 * Granular permissions for the OmniTix admin API.
 *
 * Every admin endpoint checks for a permission, not a role.
 * Roles map to sets of permissions below.
 */
enum Permission: string
{
    // ─── Events ─────────────────────────────────────────
    case EVENT_VIEW       = 'event.view';
    case EVENT_CREATE     = 'event.create';
    case EVENT_UPDATE     = 'event.update';
    case EVENT_DELETE     = 'event.delete';
    case EVENT_PUBLISH    = 'event.publish';
    case EVENT_CANCEL     = 'event.cancel';

    // ─── Venues & seat maps ─────────────────────────────
    case VENUE_VIEW       = 'venue.view';
    case VENUE_MANAGE     = 'venue.manage';
    case SEATMAP_VIEW     = 'seatmap.view';
    case SEATMAP_MANAGE   = 'seatmap.manage';

    // ─── Pricing & promos ───────────────────────────────
    case PRICING_VIEW     = 'pricing.view';
    case PRICING_MANAGE   = 'pricing.manage';
    case PROMO_VIEW       = 'promo.view';
    case PROMO_MANAGE     = 'promo.manage';

    // ─── Sales & reports ────────────────────────────────
    case SALES_VIEW       = 'sales.view';
    case REPORT_VIEW      = 'report.view';
    case REPORT_EXPORT    = 'report.export';

    // ─── Attendees & scanning ───────────────────────────
    case ATTENDEE_VIEW    = 'attendee.view';
    case TICKET_SCAN      = 'ticket.scan';

    // ─── Refunds & finance ──────────────────────────────
    case REFUND_VIEW      = 'refund.view';
    case REFUND_ISSUE     = 'refund.issue';
    case PAYOUT_VIEW      = 'payout.view';
    case PAYOUT_MANAGE    = 'payout.manage';

    // ─── Organizer staff ────────────────────────────────
    case MEMBER_VIEW      = 'member.view';
    case MEMBER_MANAGE    = 'member.manage';

    // ─── Super-admin ────────────────────────────────────
    case SUPER_ADMIN      = 'super.admin';
}