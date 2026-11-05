<?php

namespace App\Services;

use App\Models\Event;
use App\Models\PromoCode;
use Illuminate\Support\Carbon;

class PromoCodeService
{
    /**
     * Validate a code against a candidate cart.
     *
     * @param  array<int,array{seat_id:string,price_tier_id:string,price_cents:int,section_code?:string}>  $items
     * @return array{valid: bool, code?: string, reason?: string, discount_cents?: int, promo_id?: string}
     */
    public function validate(
        string $code,
        string $organizerId,
        string $eventId,
        array $items,
        ?string $userId = null,
    ): array {
        $promo = PromoCode::where('organizer_id', $organizerId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $promo) {
            return ['valid' => false, 'reason' => 'code_not_found'];
        }
        if ($promo->event_id !== null && $promo->event_id !== $eventId) {
            return ['valid' => false, 'reason' => 'wrong_event'];
        }
        if ($promo->starts_at && now()->lt($promo->starts_at)) {
            return ['valid' => false, 'reason' => 'not_yet_active'];
        }
        if ($promo->expires_at && now()->gt($promo->expires_at)) {
            return ['valid' => false, 'reason' => 'expired'];
        }
        if ($promo->max_redemptions !== null && $promo->redemptions_count >= $promo->max_redemptions) {
            return ['valid' => false, 'reason' => 'exhausted'];
        }
        if ($userId) {
            $userRedemptions = $promo->redemptions()->where('user_id', $userId)->count();
            if ($userRedemptions >= $promo->max_redemptions_per_user) {
                return ['valid' => false, 'reason' => 'per_user_limit'];
            }
        }

        $subtotal = collect($items)->sum('price_cents');
        if ($subtotal < ($promo->min_order_cents ?? 0)) {
            return ['valid' => false, 'reason' => 'below_minimum'];
        }

        // Section/tier applicability
        $applicableSections = $promo->applicable_sections ?? [];
        $applicableTiers    = $promo->applicable_tier_ids ?? [];
        $eligible = collect($items)->filter(function ($item) use ($applicableSections, $applicableTiers) {
            $sectionOk = empty($applicableSections) || in_array($item['section_code'] ?? null, $applicableSections, true);
            $tierOk    = empty($applicableTiers)    || in_array($item['price_tier_id'] ?? null, $applicableTiers, true);
            return $sectionOk && $tierOk;
        });

        if ($eligible->isEmpty()) {
            return ['valid' => false, 'reason' => 'no_eligible_items'];
        }

        $eligibleSubtotal = $eligible->sum('price_cents');

        $discount = match ($promo->discount_type) {
            'percent' => (int) round($eligibleSubtotal * ($promo->discount_value / 10000)),
            'fixed'   => min($promo->discount_value, $eligibleSubtotal),
            'bogo'    => $this->bogoDiscount($eligible),
            default   => 0,
        };

        if ($promo->max_discount_cents !== null) {
            $discount = min($discount, $promo->max_discount_cents);
        }

        return [
            'valid'          => true,
            'code'           => $promo->code,
            'discount_cents' => $discount,
            'promo_id'       => $promo->id,
            'description'    => $promo->description,
        ];
    }

    private function bogoDiscount($items): int
    {
        // Buy N get N: sort by price, discount the cheapest half.
        $prices = $items->pluck('price_cents')->sort()->values();
        $half   = (int) floor($prices->count() / 2);
        return $prices->take($half)->sum();
    }
}