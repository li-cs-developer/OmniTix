<?php

namespace Tests\Feature;

use App\Enums\OrganizerRole;
use App\Enums\UserRole;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    public function test_scanner_cannot_create_events(): void
    {
        [$user] = $this->makeStaff(OrganizerRole::SCANNER);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/events', [
                'title' => 'Test Event',
                'slug' => 'test-event',
                'venue_id' => 'x',
                'seat_map_id' => 'x',
                'category' => 'music',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'forbidden']);
    }

    public function test_owner_can_create_events(): void
    {
        [$user] = $this->makeStaff(OrganizerRole::OWNER);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/events', [
                'title' => 'Test Event',
                'slug' => 'test-event-'.uniqid(),
                'venue_id' => 'x',
                'seat_map_id' => 'x',
                'category' => 'music',
            ]);

        $response->assertStatus(422);
    }

    private function makeStaff(OrganizerRole $role): array
    {
        $organizer = Organizer::create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
            'email' => uniqid().'@test.test',
        ]);

        $user = User::create([
            'organizer_id' => $organizer->id,
            'email' => uniqid().'@test.test',
            'password' => 'password',
            'first_name' => 'Test',
            'last_name' => 'User',
            'role' => UserRole::OrganizerOwner,
            'email_verified_at' => now(),
        ]);

        OrganizerMember::create([
            'organizer_id' => $organizer->id,
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return [$user, $organizer];
    }
}