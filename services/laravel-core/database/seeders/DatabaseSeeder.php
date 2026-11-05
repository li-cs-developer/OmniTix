<?php

namespace Database\Seeders;

use App\Enums\OrganizerKycStatus;
use App\Enums\OrganizerRole;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventTierPricing;
use App\Models\GaZone;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\PriceTier;
use App\Models\PromoCode;
use App\Models\SeatMap;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Organizer ─────────────────────────────────
        $organizer = Organizer::updateOrCreate(
            ['slug' => 'demo-events'],
            [
                'name'            => 'Demo Events Co.',
                'email'           => 'hello@demo-events.test',
                'country'         => 'US',
                'currency'        => 'USD',
                'timezone'        => 'America/New_York',
                'kyc_status'      => OrganizerKycStatus::Approved,
                'kyc_verified_at' => now(),
                'payout_schedule' => 'weekly',
                'holdback_days'   => 7,
                'settings'        => ['theme_color' => '#6c5ce7'],
            ]
        );

        // ─── Owner ─────────────────────────────────────
        $owner = User::updateOrCreate(
            ['email' => 'owner@omnitix.test'],
            [
                'organizer_id'      => $organizer->id,
                'password'          => 'password',
                'first_name'        => 'Olivia',
                'last_name'         => 'Owner',
                'email_verified_at' => now(),
                'role'              => UserRole::OrganizerOwner,
            ]
        );

        OrganizerMember::updateOrCreate(
            ['organizer_id' => $organizer->id, 'user_id' => $owner->id],
            [
                'role'        => OrganizerRole::OWNER,
                'accepted_at' => now(),
            ]
        );

        // ─── Admin ─────────────────────────────────────
        $admin = User::updateOrCreate(
            ['email' => 'admin@omnitix.test'],
            [
                'organizer_id'      => $organizer->id,
                'password'          => 'password',
                'first_name'        => 'Adam',
                'last_name'         => 'Admin',
                'email_verified_at' => now(),
                'role'              => UserRole::OrganizerAdmin,
            ]
        );

        OrganizerMember::updateOrCreate(
            ['organizer_id' => $organizer->id, 'user_id' => $admin->id],
            [
                'role'          => OrganizerRole::ADMIN,
                'invited_by_id' => $owner->id,
                'accepted_at'   => now(),
            ]
        );

        // ─── Buyer ─────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'buyer@omnitix.test'],
            [
                'password'          => 'password',
                'first_name'        => 'Bea',
                'last_name'         => 'Buyer',
                'email_verified_at' => now(),
                'role'              => UserRole::Buyer,
            ]
        );

        // ═══════════════════════════════════════════════════════
        // Venue + Seat Map + Seats + GA Zone
        // ═══════════════════════════════════════════════════════

        $venue = Venue::updateOrCreate(
            ['slug' => 'demo-arena'],
            [
                'organizer_id'    => $organizer->id,
                'name'            => 'Demo Arena',
                'description'     => 'A 500-seat demo venue for local development.',
                'address_line_1'  => '123 Main St',
                'city'            => 'Austin',
                'state'           => 'TX',
                'postal_code'     => '78701',
                'country'         => 'US',
                'latitude'        => 30.2672,
                'longitude'       => -97.7431,
                'timezone'        => 'America/Chicago',
                'total_capacity'  => 500,
                'settings'        => ['has_parking' => true],
            ]
        );

        $seatMap = SeatMap::updateOrCreate(
            ['venue_id' => $venue->id, 'version' => 1],
            [
                'name'         => 'Original layout',
                'width'        => 1000,
                'height'       => 800,
                'meta'         => [
                    'orientation' => 'stage_up',
                    'stage'       => ['x' => 250, 'y' => 0, 'w' => 500, 'h' => 80, 'label' => 'STAGE'],
                ],
                'is_published' => true,
            ]
        );

        $orchestra = VenueSection::updateOrCreate(
            ['venue_id' => $venue->id, 'code' => 'ORCH'],
            [
                'name'          => 'Orchestra',
                'kind'          => 'seated',
                'visual'        => ['color' => '#6c5ce7'],
                'display_order' => 1,
            ]
        );

        $pit = VenueSection::updateOrCreate(
            ['venue_id' => $venue->id, 'code' => 'PIT'],
            [
                'name'          => 'Standing Pit',
                'kind'          => 'ga',
                'visual'        => ['color' => '#fdcb6e'],
                'display_order' => 2,
            ]
        );

        if ($orchestra->seats()->count() === 0) {
            $rows = range('A', 'J');
            $seatsPerRow = 40;

            $seatInserts = [];
            $now = now();

            foreach ($rows as $rowIndex => $rowLabel) {
                for ($n = 1; $n <= $seatsPerRow; $n++) {
                    $x = 100 + ($n - 1) * 20;
                    $y = 150 + $rowIndex * 40;

                    $attributes = [];
                    if ($n === 1 || $n === $seatsPerRow) {
                        $attributes['aisle'] = true;
                    }
                    if ($rowIndex === 0 && $n <= 4) {
                        $attributes['wheelchair'] = true;
                        $attributes['companion']  = ($n % 2 === 0);
                    }

                    $seatInserts[] = [
                        'id'                => (string) Str::ulid(),
                        'seat_map_id'       => $seatMap->id,
                        'venue_section_id'  => $orchestra->id,
                        'section'           => 'ORCH',
                        'row_label'         => $rowLabel,
                        'seat_number'       => (string) $n,
                        'display_label'     => "Orchestra {$rowLabel}{$n}",
                        'x'                 => $x,
                        'y'                 => $y,
                        'rotation'          => 0,
                        'attributes'        => json_encode($attributes),
                        'meta'              => json_encode([]),
                        'is_active'         => true,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];
                }
            }

            foreach (array_chunk($seatInserts, 200) as $chunk) {
                DB::table('seats')->insert($chunk);
            }
        }

        GaZone::updateOrCreate(
            ['seat_map_id' => $seatMap->id, 'code' => 'PIT'],
            [
                'venue_section_id' => $pit->id,
                'name'             => 'Standing Pit',
                'capacity'         => 100,
                'visual'           => [
                    'path'    => 'M 300 600 L 700 600 L 700 700 L 300 700 Z',
                    'label_x' => 500,
                    'label_y' => 650,
                ],
                'is_active'        => true,
            ]
        );

        // ═══════════════════════════════════════════════════════
        // Event + Occurrence + Price Tiers + Event Tier Pricing
        // ═══════════════════════════════════════════════════════

        $event = Event::updateOrCreate(
            ['slug' => 'demo-rock-night'],
            [
                'organizer_id'          => $organizer->id,
                'venue_id'              => $venue->id,
                'seat_map_id'           => $seatMap->id,
                'title'                 => 'Demo Rock Night',
                'short_description'     => 'An evening of live rock music.',
                'description'           => 'Join us for an electrifying night of live rock music featuring local and touring bands.',
                'category'              => 'music',
                'tags'                  => ['rock', 'live', '18+'],
                'performer_name'        => 'The Demo Band',
                'age_restriction'       => 18,
                'sales_start_at'        => now()->subDay(),
                'sales_end_at'          => now()->addMonths(2),
                'status'                => 'on_sale',
                'max_tickets_per_user'  => 8,
                'max_tickets_per_card'  => 8,
                'refund_cutoff_hours'   => 48,
                'settings'              => ['allow_resale' => false],
            ]
        );

        $occurrence = EventOccurrence::updateOrCreate(
            ['event_id' => $event->id, 'starts_at' => now()->addMonths(1)->setTime(20, 0)],
            [
                'ends_at'         => now()->addMonths(1)->setTime(23, 0),
                'doors_open_at'   => now()->addMonths(1)->setTime(19, 0),
                'label'           => 'Opening Night',
                'capacity_total'  => 500,
                'status'          => 'on_sale',
            ]
        );

        $tierAdult = PriceTier::updateOrCreate(
            ['organizer_id' => $organizer->id, 'code' => 'ADULT'],
            [
                'name'          => 'Adult',
                'description'   => 'Standard admission',
                'min_age'       => 18,
                'color'         => '#6c5ce7',
                'display_order' => 1,
            ]
        );

        $tierVip = PriceTier::updateOrCreate(
            ['organizer_id' => $organizer->id, 'code' => 'VIP'],
            [
                'name'          => 'VIP Meet & Greet',
                'description'   => 'Includes backstage access',
                'min_age'       => 18,
                'color'         => '#fdcb6e',
                'display_order' => 3,
            ]
        );

        EventTierPricing::updateOrCreate(
            ['event_id' => $event->id, 'price_tier_id' => $tierAdult->id],
            [
                'applicable_sections'    => ['ORCH'],
                'price_cents'            => 7500,
                'service_fee_cents'      => 500,
                'facility_fee_cents'     => 200,
                'processing_fee_cents'   => 300,
                'tax_rate'               => 0.0825,
                'tax_inclusive'          => 'exclusive',
                'quantity_cap'           => null,
                'display_order'          => 1,
            ]
        );

        EventTierPricing::updateOrCreate(
            ['event_id' => $event->id, 'price_tier_id' => $tierVip->id],
            [
                'applicable_sections'    => ['ORCH'],
                'price_cents'            => 25000,
                'service_fee_cents'      => 1500,
                'facility_fee_cents'     => 500,
                'processing_fee_cents'   => 500,
                'tax_rate'               => 0.0825,
                'tax_inclusive'          => 'exclusive',
                'quantity_cap'           => 20,
                'display_order'          => 3,
            ]
        );

        PromoCode::updateOrCreate(
            ['organizer_id' => $organizer->id, 'code' => 'WELCOME10'],
            [
                'event_id'                    => null,
                'description'                 => '10% off, first-time buyers',
                'discount_type'               => 'percent',
                'discount_value'              => 1000,
                'max_discount_cents'          => 5000,
                'max_redemptions'             => 1000,
                'max_redemptions_per_user'    => 1,
                'min_order_cents'             => 0,
                'starts_at'                   => now()->subDay(),
                'expires_at'                  => now()->addMonths(3),
                'is_active'                   => true,
            ]
        );

        // ─── Remove any CHILD tier created by prior runs ───────
        // The child tier was removed from the product; delete it from
        // existing databases so a re-seed leaves a clean state. The
        // `event_tier_pricing` cascade (FK) handles the dependent rows.
        PriceTier::where('organizer_id', $organizer->id)
            ->where('code', 'CHILD')
            ->delete();

        $this->command->info('✅ Seeded: full stack — 1 organizer, 3 users, 2 memberships, 1 venue, 400 seats, 1 GA zone, 1 event, 1 occurrence, 2 tiers (adult + VIP), 1 promo.');
    }
}