<?php

use App\Http\Controllers\Web\AdditionalWorkApprovalController;
use App\Http\Controllers\Web\AdminOperationsController;
use App\Http\Controllers\Web\BoardController;
use App\Http\Controllers\Web\ClientPanelController;
use App\Http\Controllers\Web\ClientPortalAuthController;
use App\Http\Controllers\Web\ClientPortalController;
use App\Http\Controllers\Web\ClientServiceRequestController;
use App\Http\Controllers\Web\CommercialPanelController;
use App\Http\Controllers\Web\EmergencyReportController;
use App\Http\Controllers\Web\FinanceInsightsController;
use App\Http\Controllers\Web\FleetController;
use App\Http\Controllers\Web\HumanResourcesController;
use App\Http\Controllers\Web\IntelligencePanelController;
use App\Http\Controllers\Web\InventoryPanelController;
use App\Http\Controllers\Web\MaintenancePlanController;
use App\Http\Controllers\Web\NotificationPanelController;
use App\Http\Controllers\Web\OperationsInsightsController;
use App\Http\Controllers\Web\PanelAuthController;
use App\Http\Controllers\Web\PerformanceDashboardController;
use App\Http\Controllers\Web\ProcurementPanelController;
use App\Http\Controllers\Web\SalesPortalController;
use App\Http\Controllers\Web\SubcontractorPanelController;
use App\Http\Controllers\Web\TeamController;
use App\Http\Controllers\Web\TwoFactorController;
use App\Http\Controllers\Web\VisitFeedbackController;
use Illuminate\Http\Request;
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

Route::post('locale/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, ['ar', 'en'], true), 404);
    $request->session()->put('locale', $locale);

    return back();
})->whereIn('locale', ['ar', 'en'])->name('locale.switch');

Route::redirect('sales', '/sales/app')->name('sales.entry');
Route::view('sales/offline', 'sales.offline')->name('sales.offline');
Route::get('sales/manifest.webmanifest', fn () => response()->view('sales.manifest')->header('Content-Type', 'application/manifest+json'))
    ->name('sales.manifest');
Route::get('sales/service-worker.js', fn () => response()->view('sales.service-worker')->header('Content-Type', 'application/javascript'))
    ->name('sales.service-worker');

Route::view('offline', 'client.offline')->name('client.offline');
Route::get('client/manifest.webmanifest', fn () => response()->view('client.manifest')->header('Content-Type', 'application/manifest+json'))
    ->name('client.manifest');
Route::get('client/service-worker.js', fn () => response()->view('client.service-worker')->header('Content-Type', 'application/javascript'))
    ->name('client.service-worker');
Route::get('client/login', [ClientPortalAuthController::class, 'show'])->name('client.login');
Route::post('client/login', [ClientPortalAuthController::class, 'login'])->middleware('throttle:10,1');

Route::get('report/{token}', [EmergencyReportController::class, 'create'])->name('emergency.create');
Route::post('report/{token}', [EmergencyReportController::class, 'store'])
    ->middleware('throttle:5,10')->name('emergency.store');
Route::get('report-received/{reference}', [EmergencyReportController::class, 'received'])
    ->middleware('throttle:30,1')->name('emergency.received');

Route::middleware(['auth:client', 'client.active'])->prefix('client')->group(function () {
    Route::post('logout', [ClientPortalAuthController::class, 'logout'])->name('client.logout');
    Route::get('/', [ClientPortalController::class, 'home'])->name('client.home');
    Route::get('visits/{visit}', [ClientPortalController::class, 'visit'])->name('client.visit');
    Route::get('request-service', [ClientServiceRequestController::class, 'create'])->name('client.service-request');
    Route::post('request-service', [ClientServiceRequestController::class, 'store'])->middleware('throttle:10,10')->name('client.service-request.store');
    Route::post('visits/{visit}/feedback', [VisitFeedbackController::class, 'store'])->name('client.visit.feedback');
    Route::get('visits/{visit}/report.pdf', [ClientPortalController::class, 'report'])->name('client.visit.report');
    Route::post('visits/{visit}/disputes', [ClientPortalController::class, 'dispute'])->name('client.visit.dispute');
    Route::get('assets/{asset}/history.pdf', [ClientPortalController::class, 'assetHistory'])->name('client.asset.history');
    Route::get('quotations/{quotation}', [ClientPortalController::class, 'quotation'])->name('client.quotation');
    Route::post('quotations/{quotation}/accept', [ClientPortalController::class, 'acceptQuotation'])->name('client.quotation.accept');
    Route::get('contracts/{contract}', [ClientPortalController::class, 'contract'])->name('client.contract');
    Route::post('contracts/{contract}/sign', [ClientPortalController::class, 'signContract'])->name('client.contract.sign');
    Route::get('payments/{payment}/receipt.pdf', [FinanceInsightsController::class, 'receipt'])->name('client.payment.receipt');
    Route::get('additional-work/{approval}', [AdditionalWorkApprovalController::class, 'show'])->name('client.additional-work');
    Route::post('additional-work/{approval}/respond', [AdditionalWorkApprovalController::class, 'respond'])->name('client.additional-work.respond');
});

Route::get('login', [PanelAuthController::class, 'show'])->name('panel.login');
Route::post('login', [PanelAuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('panel.two-factor.challenge');
Route::post('two-factor-challenge', [TwoFactorController::class, 'verifyChallenge'])
    ->middleware('throttle:10,1')->name('panel.two-factor.verify');

// `auth:web` alone only asks "is someone signed in", never "are they still
// allowed to be". A disabled account kept its cookie session and the whole panel.
Route::middleware(['auth:web', 'panel.active'])->group(function () {
    Route::post('logout', [PanelAuthController::class, 'logout'])->name('panel.logout');
    Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('panel.two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])
        ->middleware('throttle:10,1')->name('panel.two-factor.confirm');
});

Route::middleware(['auth:web', 'panel.active', 'two-factor.confirmed', 'panel.area'])->group(function () {
    Route::get('board', [BoardController::class, 'index'])->name('panel.board');
    Route::get('operations', [OperationsInsightsController::class, 'calendar'])->name('panel.operations');
    Route::middleware('panel.permission:sales')->prefix('sales')->group(function () {
        Route::get('app', [SalesPortalController::class, 'index'])->name('sales.home');
        Route::post('leads', [SalesPortalController::class, 'storeLead'])->name('sales.leads.store');
        Route::post('leads/{lead}/activities', [SalesPortalController::class, 'activity'])->name('sales.leads.activity');
        Route::post('leads/{lead}/convert', [SalesPortalController::class, 'convert'])->name('sales.leads.convert');
        Route::post('leads/{lead}/attachments', [SalesPortalController::class, 'attachment'])->name('sales.leads.attachment');
        Route::get('attachments/{attachment}', [SalesPortalController::class, 'downloadAttachment'])->name('sales.attachments.download');
        Route::post('quotations', [SalesPortalController::class, 'storeQuotation'])->name('sales.quotations.store');
        Route::post('quotations/{quotation}/send', [SalesPortalController::class, 'sendQuotation'])->name('sales.quotations.send');
        Route::post('quotations/{quotation}/discount', [SalesPortalController::class, 'requestDiscount'])->name('sales.quotations.discount');
        Route::post('targets', [SalesPortalController::class, 'target'])->name('sales.targets.store');
        Route::post('push-subscriptions', [SalesPortalController::class, 'subscribePush'])->name('sales.push.subscribe');
        Route::get('notifications.json', [SalesPortalController::class, 'notifications'])->name('sales.notifications');
    });
    if (config('darak.experimental_analytics')) {
        Route::middleware('panel.permission:performance')->prefix('performance')->group(function () {
            Route::get('/', [PerformanceDashboardController::class, 'index'])->name('panel.performance');
            Route::get('export.csv', [PerformanceDashboardController::class, 'csv'])->name('panel.performance.csv');
            Route::get('export.pdf', [PerformanceDashboardController::class, 'pdf'])->name('panel.performance.pdf');
            Route::put('settings', [PerformanceDashboardController::class, 'updateSettings'])->name('panel.performance.settings');
        });
    }
    Route::post('operations/visits/{visit}/reschedule', [OperationsInsightsController::class, 'reschedule'])->name('panel.operations.reschedule');
    Route::post('operations/absences', [OperationsInsightsController::class, 'absence'])->name('panel.operations.absence');
    Route::post('operations/vehicle-outages', [OperationsInsightsController::class, 'outage'])->name('panel.operations.outage');
    Route::post('operations/disputes/{dispute}', [OperationsInsightsController::class, 'resolveDispute'])->name('panel.operations.dispute');
    if (config('darak.experimental_analytics')) {
        Route::get('intelligence', [IntelligencePanelController::class, 'index'])->name('panel.intelligence');
        Route::post('intelligence/articles', [IntelligencePanelController::class, 'article'])->name('panel.intelligence.article');
        Route::post('intelligence/failures', [IntelligencePanelController::class, 'failure'])->name('panel.intelligence.failure');
        Route::post('intelligence/train', [IntelligencePanelController::class, 'train'])->name('panel.intelligence.train');
    }
    Route::post('operations/service-requests/{serviceRequest}/convert', [ClientServiceRequestController::class, 'convert'])->name('panel.service-request.convert');
    Route::post('operations/service-requests/{serviceRequest}/reject', [ClientServiceRequestController::class, 'reject'])->name('panel.service-request.reject');
    Route::post('operations/feedback/{feedback}/review', [VisitFeedbackController::class, 'review'])->name('panel.feedback.review');
    Route::get('maintenance', [MaintenancePlanController::class, 'index'])->name('panel.maintenance');
    Route::post('maintenance', [MaintenancePlanController::class, 'store'])->name('panel.maintenance.store');
    Route::post('maintenance/generate', [MaintenancePlanController::class, 'generate'])->name('panel.maintenance.generate');
    Route::post('maintenance/{plan}/toggle', [MaintenancePlanController::class, 'toggle'])->name('panel.maintenance.toggle');
    Route::get('visits/{visit}', [BoardController::class, 'show'])->name('panel.visit');
    Route::post('visits/{visit}/assign', [BoardController::class, 'assign'])->name('panel.visit.assign');
    Route::post('visits/{visit}/rework', [BoardController::class, 'overrideRework'])->name('panel.visit.rework');
    Route::post('visits/{visit}/additional-work', [AdditionalWorkApprovalController::class, 'store'])->name('panel.visit.additional-work');

    Route::get('clients', [ClientPanelController::class, 'index'])->name('panel.clients');
    Route::post('clients', [ClientPanelController::class, 'store'])->name('panel.clients.store');
    Route::get('clients/{client}', [ClientPanelController::class, 'show'])->name('panel.client');
    Route::post('clients/{client}/sites', [ClientPanelController::class, 'storeSite'])->name('panel.client.site');
    Route::post('sites/{site}/assets', [ClientPanelController::class, 'storeAsset'])->name('panel.site.asset');
    Route::post('clients/{client}/contracts', [ClientPanelController::class, 'storeContract'])->name('panel.client.contract');
    Route::post('clients/{client}/visits', [ClientPanelController::class, 'storeVisit'])->name('panel.client.visit');
    Route::post('clients/{client}/portal-users', [ClientPanelController::class, 'storePortalUser'])->name('panel.client.portal-user');
    Route::post('client-portal-users/{portalUser}/toggle', [ClientPanelController::class, 'togglePortalUser'])->name('panel.client.portal-user.toggle');
    Route::post('sites/{site}/emergency-qr/rotate', [ClientPanelController::class, 'rotateEmergencyQr'])->name('panel.site.emergency-qr.rotate');
    Route::get('sites/{site}/emergency-qr.svg', [EmergencyReportController::class, 'qr'])->name('panel.site.emergency-qr');
    Route::get('sites/{site}/emergency-sticker', [EmergencyReportController::class, 'sticker'])->name('panel.site.emergency-sticker');

    Route::get('commercial', [CommercialPanelController::class, 'index'])->name('panel.commercial');
    Route::get('finance', [FinanceInsightsController::class, 'index'])->name('panel.finance');
    Route::post('finance/costs', [FinanceInsightsController::class, 'cost'])->name('panel.finance.cost');

    Route::middleware('panel.permission:hr')->prefix('human-resources')->group(function () {
        Route::get('/', [HumanResourcesController::class, 'index'])->name('panel.hr');
        Route::put('employees/{user}/profile', [HumanResourcesController::class, 'profile'])->name('panel.hr.profile');
        Route::post('employees/{user}/documents', [HumanResourcesController::class, 'document'])->name('panel.hr.document');
        Route::get('documents/{document}/download', [HumanResourcesController::class, 'download'])->name('panel.hr.document.download');
        Route::post('leaves', [HumanResourcesController::class, 'leave'])->name('panel.hr.leave');
        Route::post('leaves/{leave}/decision', [HumanResourcesController::class, 'leaveDecision'])->name('panel.hr.leave.decision');
    });

    Route::middleware('panel.permission:fleet')->prefix('fleet')->group(function () {
        Route::get('/', [FleetController::class, 'index'])->name('panel.fleet');
        Route::post('vehicles', [FleetController::class, 'storeVehicle'])->name('panel.fleet.vehicle');
        Route::put('vehicles/{vehicle}', [FleetController::class, 'updateVehicle'])->name('panel.fleet.vehicle.update');
        Route::post('vehicles/{vehicle}/documents', [FleetController::class, 'document'])->name('panel.fleet.document');
        Route::get('documents/{document}/download', [FleetController::class, 'download'])->name('panel.fleet.document.download');
        Route::post('vehicles/{vehicle}/maintenance', [FleetController::class, 'maintenance'])->name('panel.fleet.maintenance');
        Route::post('maintenance/{order}/transition', [FleetController::class, 'maintenanceTransition'])->name('panel.fleet.maintenance.transition');
        Route::post('vehicles/{vehicle}/inspections', [FleetController::class, 'inspection'])->name('panel.fleet.inspection');
        Route::get('inspections/{inspection}/photo', [FleetController::class, 'inspectionPhoto'])->name('panel.fleet.inspection.photo');
        Route::post('vehicles/{vehicle}/expenses', [FleetController::class, 'expense'])->name('panel.fleet.expense');
    });

    Route::get('organization-logo/{company}', [AdminOperationsController::class, 'companyLogo'])
        ->name('panel.organization.logo');

    Route::middleware('panel.permission:admin')->prefix('admin-operations')->group(function () {
        Route::get('/', [AdminOperationsController::class, 'index'])->name('panel.admin-operations');
        Route::get('organization', [AdminOperationsController::class, 'organization'])->name('panel.admin.organization');
        Route::put('companies/{company}', [AdminOperationsController::class, 'updateCompany'])->name('panel.admin.company.update');
        Route::post('companies', [AdminOperationsController::class, 'company'])->name('panel.admin.company');
        Route::post('branches', [AdminOperationsController::class, 'branch'])->name('panel.admin.branch');
        Route::post('assign-branch', [AdminOperationsController::class, 'assignBranch'])->name('panel.admin.assign-branch');
        Route::post('leads', [AdminOperationsController::class, 'lead'])->name('panel.admin.lead');
        Route::post('leads/{lead}/activities', [AdminOperationsController::class, 'activity'])->name('panel.admin.activity');
        Route::post('vehicle-expenses', [AdminOperationsController::class, 'expense'])->name('panel.admin.expense');
        Route::post('commission-rules', [AdminOperationsController::class, 'commissionRule'])->name('panel.admin.commission-rule');
        Route::post('commission-entries', [AdminOperationsController::class, 'commissionEntry'])->name('panel.admin.commission-entry');
        Route::post('approvals', [AdminOperationsController::class, 'approvalRequest'])->name('panel.admin.approval');
        Route::post('approvals/{approval}/approve', [AdminOperationsController::class, 'approve'])->name('panel.admin.approval.approve');
        Route::post('users/{user}/permissions', [AdminOperationsController::class, 'permissions'])->name('panel.admin.permissions');
    });
    Route::post('commercial/quotations', [CommercialPanelController::class, 'storeQuotation'])->name('panel.quotation.store');
    Route::post('commercial/quotations/{quotation}/revise', [CommercialPanelController::class, 'revise'])->name('panel.quotation.revise');
    Route::post('commercial/quotations/{quotation}/send', [CommercialPanelController::class, 'send'])->name('panel.quotation.send');
    Route::post('commercial/quotations/{quotation}/convert', [CommercialPanelController::class, 'convert'])->name('panel.quotation.convert');
    Route::post('commercial/installments/{installment}/payments', [CommercialPanelController::class, 'payment'])->name('panel.installment.payment');

    Route::get('inventory', [InventoryPanelController::class, 'index'])->name('panel.inventory');
    Route::post('inventory/parts', [InventoryPanelController::class, 'storePart'])->name('panel.inventory.part');
    Route::post('inventory/receipt', [InventoryPanelController::class, 'receipt'])->name('panel.inventory.receipt');
    Route::post('inventory/transfer', [InventoryPanelController::class, 'transferToVehicle'])->name('panel.inventory.transfer');
    Route::post('inventory/adjust', [InventoryPanelController::class, 'adjust'])->name('panel.inventory.adjust');

    Route::get('procurement', [ProcurementPanelController::class, 'index'])->name('panel.procurement');
    Route::post('procurement/suppliers', [ProcurementPanelController::class, 'supplier'])->name('panel.procurement.supplier');
    Route::post('procurement/suppliers/{supplier}/parts', [ProcurementPanelController::class, 'supplierPart'])->name('panel.procurement.supplier-part');
    Route::post('procurement/orders', [ProcurementPanelController::class, 'order'])->name('panel.procurement.order');
    Route::post('procurement/rfqs', [ProcurementPanelController::class, 'createRfq'])->name('panel.procurement.rfq');
    Route::post('procurement/rfqs/{rfq}/supplier-quotations', [ProcurementPanelController::class, 'supplierQuotation'])->name('panel.procurement.rfq.quote');
    Route::post('procurement/rfqs/{rfq}/award/{quote}', [ProcurementPanelController::class, 'awardRfq'])->name('panel.procurement.rfq.award');
    Route::post('procurement/items/{item}/receive', [ProcurementPanelController::class, 'receive'])->name('panel.procurement.receive');
    Route::post('procurement/visits/{visit}/reserve', [ProcurementPanelController::class, 'reserve'])->name('panel.procurement.reserve');
    Route::post('procurement/reservations/{reservation}/release', [ProcurementPanelController::class, 'release'])->name('panel.procurement.release');
    Route::post('procurement/transfers', [ProcurementPanelController::class, 'transfer'])->name('panel.procurement.transfer');
    Route::post('procurement/transfers/{transfer}/release', [ProcurementPanelController::class, 'releaseTransfer'])->name('panel.procurement.transfer.release');
    Route::post('procurement/transfers/{transfer}/accept', [ProcurementPanelController::class, 'acceptTransfer'])->name('panel.procurement.transfer.accept');
    Route::post('procurement/replenishment/scan', [ProcurementPanelController::class, 'scanReplenishment'])->name('panel.procurement.replenishment.scan');
    Route::post('procurement/stocktakes', [ProcurementPanelController::class, 'createStocktake'])->name('panel.procurement.stocktake');
    Route::post('procurement/stocktakes/{session}/complete', [ProcurementPanelController::class, 'completeStocktake'])->name('panel.procurement.stocktake.complete');

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
    Route::post('team/custodies', [TeamController::class, 'issueCustody'])->name('panel.team.custody.issue');
    Route::post('team/custodies/{custody}/accept', [TeamController::class, 'acceptCustody'])->name('panel.team.custody.accept');
    Route::post('team/custodies/{custody}/return', [TeamController::class, 'returnCustody'])->name('panel.team.custody.return');
    Route::post('team/devices/{device}/revoke', [TeamController::class, 'revokeDevice'])->name('panel.team.revoke');
    Route::post('team/users/{user}/two-factor-reset', [TwoFactorController::class, 'reset'])
        ->name('panel.team.two-factor-reset');
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('panel.two-factor.recovery-codes');

    Route::get('notifications', [NotificationPanelController::class, 'index'])->name('panel.notifications');
    Route::get('emergencies', [EmergencyReportController::class, 'index'])->name('panel.emergencies');
    Route::get('emergencies/{emergency}', [EmergencyReportController::class, 'show'])->name('panel.emergency');
    Route::get('emergencies/{emergency}/photo', [EmergencyReportController::class, 'photo'])->name('panel.emergency.photo');
    Route::post('emergencies/{emergency}/convert', [EmergencyReportController::class, 'convert'])->name('panel.emergency.convert');
    Route::post('emergencies/{emergency}/reject', [EmergencyReportController::class, 'reject'])->name('panel.emergency.reject');
    Route::post('notifications/run', [NotificationPanelController::class, 'run'])->name('panel.notifications.run');
    Route::post('notifications/{message}/sent', [NotificationPanelController::class, 'markSent'])->name('panel.notifications.sent');
    Route::post('notifications/{message}/retry', [NotificationPanelController::class, 'retry'])->name('panel.notifications.retry');
});
