<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Darak MVP API (v1)
|--------------------------------------------------------------------------
| Scope is PRD v1.2 §2. Dispatch scoring, sales rep, client portal, commissions
| and profitability analytics are deliberately absent — they are backlog and are
| re-prioritised after four weeks of real operation.
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'device.active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('devices/{device}/revoke', [AuthController::class, 'revokeDevice']);

        // Offline sync
        Route::get('sync/bootstrap', [SyncController::class, 'bootstrap']);
        Route::post('sync/events', [SyncController::class, 'events']);

        // Visits
        Route::get('visits', [VisitController::class, 'index']);
        Route::get('visits/{visit}', [VisitController::class, 'show']);
        Route::get('visits/{visit}/close-blockers', [VisitController::class, 'closeBlockers']);
        Route::post('visits/{visit}/transition', [VisitController::class, 'transition']);
        Route::post('visits/{visit}/assign', [VisitController::class, 'assign']);
        Route::post('visits/{visit}/rework-override', [VisitController::class, 'overrideRework']);

        // Evidence — resumable upload
        Route::get('media/{clientMediaId}/status', [MediaController::class, 'status']);
        Route::post('media/{clientMediaId}/chunk', [MediaController::class, 'chunk']);
        Route::post('media/{clientMediaId}/complete', [MediaController::class, 'complete']);
        // The way out for evidence that will never upload.
        Route::post('media/{clientMediaId}/discard', [MediaController::class, 'discard']);

        // Inventory — back office only. A field device has no business creating
        // warehouse receipts or reading the whole company's stock.
        Route::middleware('role.backoffice')->group(function () {
            Route::get('inventory/locations/{location}/balances', [InventoryController::class, 'balances']);
            Route::post('inventory/receipt', [InventoryController::class, 'receipt']);
            Route::post('inventory/vehicle-load', [InventoryController::class, 'vehicleLoad']);
            Route::post('inventory/return', [InventoryController::class, 'returnPart']);
        });

        // The visit report is scoped by the visit policy; the bulk exports are
        // company-wide data and stay back office.
        Route::get('visits/{visit}/report.pdf', [ReportController::class, 'visitPdf']);

        Route::middleware('role.backoffice')->group(function () {
            Route::get('reports/visits.csv', [ReportController::class, 'visitsCsv']);
            Route::get('reports/stock-moves.csv', [ReportController::class, 'stockMovesCsv']);
            Route::get('reports/first-time-fix', [ReportController::class, 'firstTimeFix']);
        });
    });
});
