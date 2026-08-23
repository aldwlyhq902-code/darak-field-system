<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustodyController;
use App\Http\Controllers\Api\FleetController;
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
| Field API v1. Dispatch, diagnosis, custody, stocktake, transfer and client
| approval workflows are exposed here with server-side ownership checks.
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'device.active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('devices/{device}/revoke', [AuthController::class, 'revokeDevice']);
        Route::get('custodies', [CustodyController::class, 'index']);
        Route::post('custodies/{custody}/accept', [CustodyController::class, 'accept']);
        Route::get('fleet/vehicle', [FleetController::class, 'current']);
        Route::post('fleet/vehicle/inspection', [FleetController::class, 'inspection'])->middleware('throttle:10,1');

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
        Route::get('visits/{visit}/diagnosis-suggestions', [VisitController::class, 'diagnosisSuggestions']);
        Route::post('visits/{visit}/diagnosis', [VisitController::class, 'recordDiagnosis']);
        Route::post('visits/{visit}/additional-work', [VisitController::class, 'createAdditionalWork']);
        Route::post('visits/{visit}/location', [VisitController::class, 'updateLocation'])->middleware('throttle:30,1');
        Route::get('inventory/vehicle-transfers', [InventoryController::class, 'pendingVehicleTransfers']);
        Route::get('inventory/vehicle-transfer-releases', [InventoryController::class, 'pendingVehicleTransferReleases']);
        Route::post('inventory/vehicle-transfers/{transfer}/release', [InventoryController::class, 'releaseVehicleTransfer']);
        Route::post('inventory/vehicle-transfers/{transfer}/accept', [InventoryController::class, 'acceptVehicleTransfer']);
        Route::get('inventory/stocktakes', [InventoryController::class, 'stocktakes']);
        Route::post('inventory/stocktakes/{session}/scan', [InventoryController::class, 'scanStocktake']);
        Route::post('inventory/stocktakes/{session}/complete', [InventoryController::class, 'completeStocktake']);

        // Evidence — resumable upload
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('media/{clientMediaId}/status', [MediaController::class, 'status']);
            Route::post('media/{clientMediaId}/chunk', [MediaController::class, 'chunk']);
            Route::post('media/{clientMediaId}/complete', [MediaController::class, 'complete']);
            // The way out for evidence that will never upload.
            Route::post('media/{clientMediaId}/discard', [MediaController::class, 'discard']);
        });

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
