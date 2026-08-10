<?php

use App\Http\Controllers\Web\BoardController;
use App\Http\Controllers\Web\ClientPanelController;
use App\Http\Controllers\Web\InventoryPanelController;
use App\Http\Controllers\Web\NotificationPanelController;
use App\Http\Controllers\Web\PanelAuthController;
use App\Http\Controllers\Web\SubcontractorPanelController;
use App\Http\Controllers\Web\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Supervisor panel
|--------------------------------------------------------------------------
| Server-rendered and deliberately plain. At two vehicles a single-page app
| would cost build tooling and a second test surface for no operational gain.
| Technicians have no access here — their surface is the phone.
*/

Route::get('/', fn () => redirect()->route('panel.board'));

Route::get('login', [PanelAuthController::class, 'show'])->name('panel.login');
Route::post('login', [PanelAuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:web')->group(function () {
    Route::post('logout', [PanelAuthController::class, 'logout'])->name('panel.logout');

    Route::get('board', [BoardController::class, 'index'])->name('panel.board');
    Route::get('visits/{visit}', [BoardController::class, 'show'])->name('panel.visit');
    Route::post('visits/{visit}/assign', [BoardController::class, 'assign'])->name('panel.visit.assign');
    Route::post('visits/{visit}/rework', [BoardController::class, 'overrideRework'])->name('panel.visit.rework');

    Route::get('clients', [ClientPanelController::class, 'index'])->name('panel.clients');
    Route::post('clients', [ClientPanelController::class, 'store'])->name('panel.clients.store');
    Route::get('clients/{client}', [ClientPanelController::class, 'show'])->name('panel.client');
    Route::post('clients/{client}/sites', [ClientPanelController::class, 'storeSite'])->name('panel.client.site');
    Route::post('sites/{site}/assets', [ClientPanelController::class, 'storeAsset'])->name('panel.site.asset');
    Route::post('clients/{client}/contracts', [ClientPanelController::class, 'storeContract'])->name('panel.client.contract');
    Route::post('clients/{client}/visits', [ClientPanelController::class, 'storeVisit'])->name('panel.client.visit');

    Route::get('inventory', [InventoryPanelController::class, 'index'])->name('panel.inventory');
    Route::post('inventory/parts', [InventoryPanelController::class, 'storePart'])->name('panel.inventory.part');
    Route::post('inventory/receipt', [InventoryPanelController::class, 'receipt'])->name('panel.inventory.receipt');
    Route::post('inventory/transfer', [InventoryPanelController::class, 'transferToVehicle'])->name('panel.inventory.transfer');
    Route::post('inventory/adjust', [InventoryPanelController::class, 'adjust'])->name('panel.inventory.adjust');

    Route::get('subcontractors', [SubcontractorPanelController::class, 'index'])->name('panel.sub');
    Route::post('subcontractors/partners', [SubcontractorPanelController::class, 'storePartner'])->name('panel.sub.partner');
    Route::post('subcontractors/assign', [SubcontractorPanelController::class, 'assign'])->name('panel.sub.assign');
    Route::post('subcontractors/{order}/documents', [SubcontractorPanelController::class, 'uploadDocument'])->name('panel.sub.doc.upload');
    Route::get('subcontractors/{order}/documents/{index}', [SubcontractorPanelController::class, 'download'])->name('panel.sub.doc');
    Route::post('subcontractors/{order}/delivered', [SubcontractorPanelController::class, 'markDelivered'])->name('panel.sub.delivered');
    Route::post('subcontractors/{order}/cancel', [SubcontractorPanelController::class, 'cancel'])->name('panel.sub.cancel');

    Route::get('team', [TeamController::class, 'index'])->name('panel.team');
    Route::post('team/technicians', [TeamController::class, 'storeTechnician'])->name('panel.team.technician');
    Route::post('team/users/{user}/toggle', [TeamController::class, 'toggleActive'])->name('panel.team.toggle');
    Route::post('team/devices/{device}/revoke', [TeamController::class, 'revokeDevice'])->name('panel.team.revoke');

    Route::get('notifications', [NotificationPanelController::class, 'index'])->name('panel.notifications');
    Route::post('notifications/run', [NotificationPanelController::class, 'run'])->name('panel.notifications.run');
    Route::post('notifications/{message}/sent', [NotificationPanelController::class, 'markSent'])->name('panel.notifications.sent');
    Route::post('notifications/{message}/retry', [NotificationPanelController::class, 'retry'])->name('panel.notifications.retry');
});
