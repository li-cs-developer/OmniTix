<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCodeServiceTest extends TestCase
{
    public function test_percent_discount_capped(): void
    {
        [$promo, $event] = $this->makePromo([
            'discount_type' => 'percent',
            'discount_value' => 1000,
            'max_discount_cents' => 500,
        ]);

        $svc = new PromoCodeService();
        $result = $svc->validate('TEST10', $event->organizer_id, $event->id, [
            ['seat_id' => 's1', 'price_tier_id' => 't1', 'price_cents' => 10000],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame(500, $result['discount_cents']);
    }

    public function test_expired_code_rejected(): void
    {
        [$promo, $event] = $this->makePromo([
            'expires_at' => now()->subDay(),
        ]);

        $svc = new PromoCodeService();
        $result = $svc->validate('TEST10', $event->organizer_id, $event->id, [
            ['seat_id' => 's1', 'price_tier_id' => 't1', 'price_cents' => 10000],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
    }

    private function makePromo(array $overrides): array
    {
        $organizer = Organizer::create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
            'email' => uniqid().'@test.test',
        ]);
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $organizer->id,
            'seat_map_id' => $organizer->id,
            'title' => 'Test Event',
            'slug' => 'test-event-'.uniqid(),
            'category' => 'music',
            'status' => 'on_sale',
        ]);
        $promo = PromoCode::create(array_merge([
            'organizer_id' => $organizer->id,
            'code' => 'TEST10',
            'discount_type' => 'percent',
            'discount_value' => 1000,
            'max_redemptions_per_user' => 1,
            'is_active' => true,
        ], $overrides));

        return [$promo, $event];
    }
}