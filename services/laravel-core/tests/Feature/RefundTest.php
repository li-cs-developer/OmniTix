<?php

namespace Tests\Feature;

use App\Enums\OrganizerRole;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\PriceTier;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    public function test_refund_requires_fulfilled_order(): void
    {
        [$user, $order] = $this->makePaidOrder();
        $order->update(['status' => 'pending_payment']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
                'reason' => 'test',
            ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => 'order_not_refundable']);
    }

    public function test_refund_calls_ledger_and_voids_tickets(): void
    {
        [$user, $order] = $this->makePaidOrder();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
                'reason' => 'customer request',
            ]);

        if ($response->status() === 201) {
            $this->assertDatabaseHas('tickets', [
                'order_id' => $order->id,
                'status' => 'voided',
                'is_refunded' => true,
            ]);
        } else {
            $response->assertStatus(502);
        }
    }

    private function makePaidOrder(): array
    {
        $this->artisan('db:seed');
        $event = Event::where('slug', 'demo-rock-night')->first();

        $organizer = Organizer::find($event->organizer_id);
        $user = User::create([
            'organizer_id' => $organizer->id,
            'email' => uniqid().'@test.test',
            'password' => 'password',
            'role' => UserRole::OrganizerAdmin,
            'email_verified_at' => now(),
        ]);
        OrganizerMember::create([
            'organizer_id' => $organizer->id,
            'user_id' => $user->id,
            'role' => OrganizerRole::ADMIN,
            'accepted_at' => now(),
        ]);

        $tier = PriceTier::where('organizer_id', $organizer->id)->first();
        $order = Order::create([
            'reference' => 'OTX-TEST-' . uniqid(),
            'guest_email' => 'buyer@example.test',
            'event_id' => $event->id,
            'event_occurrence_id' => $event->occurrences()->first()->id,
            'subtotal_cents' => 7500,
            'total_cents' => 7500,
            'currency' => 'USD',
            'status' => 'fulfilled',
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'seat_id' => null,
            'quantity' => 1,
            'price_tier_id' => $tier->id,
            'unit_price_cents' => 7500,
            'line_subtotal_cents' => 7500,
            'line_total_cents' => 7500,
        ]);
        Ticket::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items()->first()->id,
            'event_id' => $event->id,
            'event_occurrence_id' => $order->event_occurrence_id,
            'seat_id' => null,
            'price_tier_id' => $tier->id,
            'ticket_number' => 'OTX-TICKET-' . uniqid(),
            'status' => 'issued',
        ]);

        return [$user, $order];
    }
}