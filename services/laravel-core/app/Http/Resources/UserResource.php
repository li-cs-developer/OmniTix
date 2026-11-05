<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'role' => $this->role?->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
                'slug' => $this->organizer->slug,
            ]),
            'memberships' => $this->whenLoaded('memberships', fn () =>
                $this->memberships->map(fn ($m) => [
                    'organizer_id' => $m->organizer_id,
                    'role' => $m->role->value,
                    'accepted_at' => $m->accepted_at?->toIso8601String(),
                ])->values()
            ),
        ];
    }
}