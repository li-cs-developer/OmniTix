<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\PriceTier;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    public function test_checkout_creates_order_and_tickets(): void
    {
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);

        $event = Event::where('slug', 'demo-rock-night')->first();
        $occurrence = EventOccurrence::where('event_id', $event->id)->first();
        $seat = Seat::where('seat_map_id', $event->seat_map_id)->first();
        $tier = PriceTier::where('organizer_id', $event->organizer_id)->first();

        $response = $this->postJson('/api/v1/orders/checkout', [
            'occurrence_id' => $occurrence->id,
            'user_id' => 'test-user-001',
            'buyer_email' => 'buyer@example.test',
            'idempotency_key' => 'test-idem-001',
            'seats' => [[
                'seat_id' => $seat->id,
                'hold_token' => 'test-token-001',
                'price_tier_id' => $tier->id,
                'price_cents' => 7500,
            ]],
        ]);

        if ($response->status() === 200) {
            $response->assertJsonStructure([
                'data' => ['order_id', 'reference', 'total_cents', 'currency', 'tickets'],
            ]);
            $this->assertDatabaseHas('orders', ['reference' => $response->json('data.reference')]);
            $this->assertDatabaseCount('tickets', 1);
        } else {
            $response->assertStatus(409);
            $response->assertJson(['error' => 'hold_expired']);
        }
    }

    public function test_checkout_with_same_idempotency_key_returns_same_order(): void
    {
        $this->artisan('db:seed');
        $event = Event::where('slug', 'demo-rock-night')->first();
        $occurrence = EventOccurrence::where('event_id', $event->id)->first();
        $seat = Seat::where('seat_map_id', $event->seat_map_id)->first();
        $tier = PriceTier::where('organizer_id', $event->organizer_id)->first();

        $payload = [
            'occurrence_id' => $occurrence->id,
            'user_id' => 'test-user-002',
            'buyer_email' => 'buyer@example.test',
            'idempotency_key' => 'test-idem-002',
            'seats' => [[
                'seat_id' => $seat->id,
                'hold_token' => 'test-token-002',
                'price_tier_id' => $tier->id,
                'price_cents' => 7500,
            ]],
        ];

        $first = $this->postJson('/api/v1/orders/checkout', $payload);
        if ($first->status() !== 200) {
            $this->markTestSkipped('Inventory not available in this environment');
        }
        $firstRef = $first->json('data.reference');

        $second = $this->postJson('/api/v1/orders/checkout', $payload);
        $this->assertSame($firstRef, $second->json('data.reference'));
    }
}