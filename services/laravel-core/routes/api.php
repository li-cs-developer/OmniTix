<?php

use App\Http\Controllers\Api\V1\Admin\AttendeeController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\EventController as AdminEventController;
use App\Http\Controllers\Api\V1\Admin\EventOccurrenceController;
use App\Http\Controllers\Api\V1\Admin\EventSalesController;
use App\Http\Controllers\Api\V1\Admin\EventTierPricingController;
use App\Http\Controllers\Api\V1\Admin\ExportController;
use App\Http\Controllers\Api\V1\Admin\OrderAdminController;
use App\Http\Controllers\Api\V1\Admin\PriceTierController;
use App\Http\Controllers\Api\V1\Admin\PromoCodeController;
use App\Http\Controllers\Api\V1\Admin\RefundController;
use App\Http\Controllers\Api\V1\Admin\SeatMapController;
use App\Http\Controllers\Api\V1\Admin\SectionController;
use App\Http\Controllers\Api\V1\Admin\VenueController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PromoController;
use App\Http\Controllers\Api\V1\ScannerController;
use App\Http\Controllers\Api\V1\SeatController;
use Illuminate\Support\Facades\Route;

// ─── Public API ─────────────────────────────────────────
Route::prefix('v1')->group(function () {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{slug}', [EventController::class, 'show']);
    Route::get('/events/{eventId}/seats', [SeatController::class, 'layout']);

    Route::post('/seats/hold', [SeatController::class, 'hold']);
    Route::post('/seats/release', [SeatController::class, 'release']);
    Route::get('/seats/states', [SeatController::class, 'states']);
    Route::post('/ga/hold', [SeatController::class, 'holdGa']);

    Route::post('/orders/checkout', [OrderController::class, 'checkout']);
    Route::post('/promos/validate', [PromoController::class, 'validateCode']);

    // ─── Guest order lookup (no login in v1) ─────────────
    Route::prefix('account')->group(function () {
        Route::post('/lookup', [AccountController::class, 'lookup']);
        Route::get('/orders/{reference}', [AccountController::class, 'order']);
    });

    // ─── Scanner API ─────────────────────────────────────
    Route::prefix('scanner')->group(function () {
        Route::get('/health', [ScannerController::class, 'health']);
        Route::post('/scan',   [ScannerController::class, 'scan']);
    });

    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'version' => '0.1.0',
        'timestamp' => now()->toIso8601String(),
    ]));

    // ─── Admin API ──────────────────────────────────────
    Route::prefix('admin')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/me', [AuthController::class, 'me']);

            // ─── Events ────────────────────────────────
            Route::get('/events', [AdminEventController::class, 'index'])
                ->middleware('perm:event.view');

            Route::post('/events', [AdminEventController::class, 'store'])
                ->middleware('perm:event.create');

            Route::get('/events/{id}', [AdminEventController::class, 'show'])
                ->middleware('perm:event.view');

            Route::patch('/events/{id}', [AdminEventController::class, 'update'])
                ->middleware('perm:event.update');

            Route::delete('/events/{id}', [AdminEventController::class, 'destroy'])
                ->middleware('perm:event.delete');

            Route::post('/events/{id}/publish', [AdminEventController::class, 'publish'])
                ->middleware('perm:event.publish');

            Route::post('/events/{id}/cancel', [AdminEventController::class, 'cancel'])
                ->middleware('perm:event.cancel');

            // ─── Event Occurrences ─────────────────────
            Route::get('/events/{eventId}/occurrences', [EventOccurrenceController::class, 'index'])
                ->middleware('perm:event.view');
            Route::post('/events/{eventId}/occurrences', [EventOccurrenceController::class, 'store'])
                ->middleware('perm:event.create');
            Route::patch('/events/{eventId}/occurrences/{occurrenceId}', [EventOccurrenceController::class, 'update'])
                ->middleware('perm:event.update');
            Route::delete('/events/{eventId}/occurrences/{occurrenceId}', [EventOccurrenceController::class, 'destroy'])
                ->middleware('perm:event.update');

            // ─── Event Tier Pricing ────────────────────
            Route::get('/events/{eventId}/tier-pricing', [EventTierPricingController::class, 'index'])
                ->middleware('perm:pricing.view');
            Route::post('/events/{eventId}/tier-pricing', [EventTierPricingController::class, 'store'])
                ->middleware('perm:pricing.manage');
            Route::patch('/events/{eventId}/tier-pricing/{pricingId}', [EventTierPricingController::class, 'update'])
                ->middleware('perm:pricing.manage');
            Route::delete('/events/{eventId}/tier-pricing/{pricingId}', [EventTierPricingController::class, 'destroy'])
                ->middleware('perm:pricing.manage');

            // ─── Exports ───────────────────────────────
            Route::get('/events/{id}/export/sales.csv', [ExportController::class, 'salesCsv'])
                ->middleware('perm:report.export');
            Route::get('/events/{id}/export/attendees.csv', [ExportController::class, 'attendeesCsv'])
                ->middleware('perm:report.export');
            Route::get('/events/{id}/export/tax.csv', [ExportController::class, 'taxCsv'])
                ->middleware('perm:report.export');

            // ─── Venues ────────────────────────────────
            Route::get('/venues', [VenueController::class, 'index'])
                ->middleware('perm:venue.view');
            Route::post('/venues', [VenueController::class, 'store'])
                ->middleware('perm:venue.manage');
            Route::get('/venues/{id}', [VenueController::class, 'show'])
                ->middleware('perm:venue.view');
            Route::patch('/venues/{id}', [VenueController::class, 'update'])
                ->middleware('perm:venue.manage');
            Route::delete('/venues/{id}', [VenueController::class, 'destroy'])
                ->middleware('perm:venue.manage');

            // ─── Venue Sections ────────────────────────
            Route::get('/venues/{venueId}/sections', [SectionController::class, 'index'])
                ->middleware('perm:venue.view');
            Route::post('/venues/{venueId}/sections', [SectionController::class, 'store'])
                ->middleware('perm:venue.manage');
            Route::patch('/venues/{venueId}/sections/{sectionId}', [SectionController::class, 'update'])
                ->middleware('perm:venue.manage');
            Route::delete('/venues/{venueId}/sections/{sectionId}', [SectionController::class, 'destroy'])
                ->middleware('perm:venue.manage');

            // ─── Seat Maps ─────────────────────────────
            Route::get('/venues/{venueId}/seat-maps', [SeatMapController::class, 'index'])
                ->middleware('perm:seatmap.view');
            Route::post('/venues/{venueId}/seat-maps', [SeatMapController::class, 'store'])
                ->middleware('perm:seatmap.manage');
            Route::get('/seat-maps/{id}', [SeatMapController::class, 'show'])
                ->middleware('perm:seatmap.view');
            Route::patch('/seat-maps/{id}', [SeatMapController::class, 'update'])
                ->middleware('perm:seatmap.manage');
            Route::post('/seat-maps/{id}/seats/bulk', [SeatMapController::class, 'bulkCreateSeats'])
                ->middleware('perm:seatmap.manage');
            Route::delete('/seat-maps/{id}', [SeatMapController::class, 'destroy'])
                ->middleware('perm:seatmap.manage');

            // ─── Price Tiers ───────────────────────────
            Route::get('/price-tiers', [PriceTierController::class, 'index'])
                ->middleware('perm:pricing.view');
            Route::post('/price-tiers', [PriceTierController::class, 'store'])
                ->middleware('perm:pricing.manage');
            Route::get('/price-tiers/{id}', [PriceTierController::class, 'show'])
                ->middleware('perm:pricing.view');
            Route::patch('/price-tiers/{id}', [PriceTierController::class, 'update'])
                ->middleware('perm:pricing.manage');
            Route::delete('/price-tiers/{id}', [PriceTierController::class, 'destroy'])
                ->middleware('perm:pricing.manage');

            // ─── Promo Codes ───────────────────────────
            Route::get('/promo-codes', [PromoCodeController::class, 'index'])
                ->middleware('perm:promo.view');
            Route::post('/promo-codes', [PromoCodeController::class, 'store'])
                ->middleware('perm:promo.manage');
            Route::get('/promo-codes/{id}', [PromoCodeController::class, 'show'])
                ->middleware('perm:promo.view');
            Route::patch('/promo-codes/{id}', [PromoCodeController::class, 'update'])
                ->middleware('perm:promo.manage');
            Route::delete('/promo-codes/{id}', [PromoCodeController::class, 'destroy'])
                ->middleware('perm:promo.manage');

            // ─── Dashboard ─────────────────────────────
            Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
                ->middleware('perm:sales.view');

            // ─── Event sales & attendees ───────────────
            Route::get('/events/{id}/sales', [EventSalesController::class, 'show'])
                ->middleware('perm:sales.view');
            Route::get('/events/{id}/attendees', [AttendeeController::class, 'index'])
                ->middleware('perm:attendee.view');

            // ─── Orders ────────────────────────────────
            Route::get('/orders', [OrderAdminController::class, 'index'])
                ->middleware('perm:sales.view');
            Route::get('/orders/{id}', [OrderAdminController::class, 'show'])
                ->middleware('perm:sales.view');

            // ─── Refunds ───────────────────────────────
            Route::get('/refunds', [RefundController::class, 'index'])
                ->middleware('perm:refund.view');
            Route::get('/orders/{id}/refunds', [RefundController::class, 'forOrder'])
                ->middleware('perm:refund.view');
            Route::post('/orders/{id}/refund', [RefundController::class, 'store'])
                ->middleware('perm:refund.issue');
        });
    });
});