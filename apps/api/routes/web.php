<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerDocumentController;
use App\Http\Controllers\DashboardOverviewController;
use App\Http\Controllers\MaintenanceCancellationController;
use App\Http\Controllers\MaintenanceFileController;
use App\Http\Controllers\MaintenanceFollowupController;
use App\Http\Controllers\MaintenanceOverviewController;
use App\Http\Controllers\MaintenancePaymentCancellationController;
use App\Http\Controllers\MaintenancePaymentCorrectionController;
use App\Http\Controllers\MaintenanceRefundCancellationController;
use App\Http\Controllers\MaintenanceRefundController;
use App\Http\Controllers\ReservationCancellationController;
use App\Http\Controllers\ReservationContractController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationDepartureController;
use App\Http\Controllers\ReservationExtensionController;
use App\Http\Controllers\ReservationForeignTravelController;
use App\Http\Controllers\ReservationFuelController;
use App\Http\Controllers\ReservationInspectionController;
use App\Http\Controllers\ReservationPaymentController;
use App\Http\Controllers\ReservationPickupController;
use App\Http\Controllers\ReservationReturnController;
use App\Http\Controllers\ReservationSettlementController;
use App\Http\Controllers\ReservationVehicleSwapController;
use App\Http\Controllers\TabletContractSignatureController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleDocumentController;
use App\Http\Controllers\VehicleLoanCancellationController;
use App\Http\Controllers\VehicleLoanController;
use App\Http\Controllers\VehicleLoanPaymentCancellationController;
use App\Http\Controllers\VehicleLoanPaymentCorrectionController;
use App\Http\Controllers\VehicleMaintenanceController;
use App\Http\Controllers\VehicleOdometerController;
use App\Http\Controllers\VehiclePhotoController;
use App\Http\Controllers\VehiclePricingController;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\RequireCompany;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/pricing')->middleware(['auth:web', RequireCompany::class, PrivateResponse::class])->group(function (): void {
    Route::get('/', [VehiclePricingController::class, 'index']);
    Route::post('/', [VehiclePricingController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/simulate', [VehiclePricingController::class, 'simulate'])->middleware('throttle:60,1');
});
Route::prefix('api/v1/reservations')->middleware(['auth:web', RequireCompany::class, PrivateResponse::class])->group(function (): void {
    Route::get('/{reservation}/drivers', [\App\Http\Controllers\ReservationDriverController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/drivers/new', [\App\Http\Controllers\ReservationDriverController::class, 'create'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/drivers', [\App\Http\Controllers\ReservationDriverController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/', [ReservationController::class, 'index']);
    Route::get('/availability', [ReservationController::class, 'availability']);
    Route::get('/planning', [ReservationController::class, 'planning']);
    Route::post('/', [ReservationController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/{reservation}/hold', [ReservationController::class, 'hold'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/quote', [ReservationPaymentController::class, 'quote'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/price-calculation', [ReservationPaymentController::class, 'calculation'])->whereNumber('reservation');
    Route::get('/{reservation}/departure/inspection', [ReservationInspectionController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/departure/inspection', [ReservationInspectionController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/departure/inspection/photos/{photo}/content', [ReservationInspectionController::class, 'content'])->whereNumber(['reservation', 'photo']);
    Route::get('/{reservation}/departure/contract', [ReservationContractController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/departure/contract', [ReservationContractController::class, 'issue'])->whereNumber('reservation')->middleware('throttle:rental-contracts');
    Route::post('/{reservation}/departure/contract/{contract}/retry', [ReservationContractController::class, 'retry'])->whereNumber(['reservation', 'contract'])->middleware('throttle:rental-contract-render-retry');
    Route::post('/{reservation}/departure/contract/{contract}/signed', [ReservationContractController::class, 'upload'])->whereNumber(['reservation', 'contract'])->middleware('throttle:rental-contracts');
    Route::post('/{reservation}/departure/contract/{contract}/tablet/preview', [TabletContractSignatureController::class, 'preview'])->whereNumber(['reservation', 'contract'])->middleware('throttle:rental-contracts');
    Route::get('/{reservation}/departure/contract/{contract}/tablet/preview/{token}', [TabletContractSignatureController::class, 'content'])->whereNumber(['reservation', 'contract'])->whereUuid('token');
    Route::post('/{reservation}/departure/contract/{contract}/tablet/confirm', [TabletContractSignatureController::class, 'confirm'])->whereNumber(['reservation', 'contract'])->middleware('throttle:rental-contracts');
    Route::post('/{reservation}/departure/contract/{contract}/verify', [ReservationContractController::class, 'verify'])->whereNumber(['reservation', 'contract'])->middleware('throttle:rental-contracts');
    Route::get('/{reservation}/departure/contract/files/{file}/content', [ReservationContractController::class, 'content'])->whereNumber(['reservation', 'file']);
    Route::get('/{reservation}/return', [ReservationReturnController::class, 'show'])->whereNumber('reservation');
    Route::get('/{reservation}/settlement', [ReservationSettlementController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/date-corrections', [\App\Http\Controllers\ReservationDateCorrectionController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/extensions', [ReservationExtensionController::class, 'show'])->whereNumber('reservation');
    Route::get('/{reservation}/extensions/quote', [ReservationExtensionController::class, 'quote'])->whereNumber('reservation');
    Route::post('/{reservation}/extensions', [ReservationExtensionController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/extensions/{agreement}/activate', [ReservationExtensionController::class, 'activate'])->whereNumber(['reservation', 'agreement'])->middleware('throttle:30,1');
    Route::get('/{reservation}/vehicle-swaps', [ReservationVehicleSwapController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/vehicle-swaps', [ReservationVehicleSwapController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/cancellation', [ReservationCancellationController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/settlement', [ReservationSettlementController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/return/fuel', [ReservationFuelController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/return/fuel', [ReservationFuelController::class, 'charge'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/return/fuel/payments', [ReservationFuelController::class, 'payment'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/return/fuel/payments/{payment}/verify', [ReservationFuelController::class, 'verify'])->whereNumber(['reservation', 'payment'])->middleware('throttle:30,1');
    Route::get('/{reservation}/return/fuel/{charge}/content', [ReservationFuelController::class, 'ticket'])->whereNumber(['reservation', 'charge']);
    Route::post('/{reservation}/return', [ReservationReturnController::class, 'receive'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/return/inspection', [ReservationReturnController::class, 'inspect'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/return/readiness', [ReservationReturnController::class, 'approve'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/return/files/{file}/content', [ReservationReturnController::class, 'content'])->whereNumber(['reservation', 'file']);
    Route::get('/{reservation}/pickup', [ReservationPickupController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/pickup', [ReservationPickupController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/departure', [ReservationDepartureController::class, 'show'])->whereNumber('reservation');
    Route::get('/{reservation}/foreign-travel', [ReservationForeignTravelController::class, 'show'])->whereNumber('reservation');
    Route::post('/{reservation}/foreign-travel', [ReservationForeignTravelController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}/foreign-travel/{authorization}/{format}', [ReservationForeignTravelController::class, 'content'])->whereNumber(['reservation', 'authorization'])->where('format', 'pdf|docx');
    Route::post('/{reservation}/departure', [ReservationDepartureController::class, 'store'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::put('/{reservation}/departure/customer', [ReservationDepartureController::class, 'customer'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/payments', [ReservationPaymentController::class, 'payment'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::post('/{reservation}/payments/{payment}/verify', [ReservationPaymentController::class, 'verify'])->whereNumber(['reservation', 'payment'])->middleware('throttle:30,1');
    Route::post('/{reservation}/confirm', [ReservationPaymentController::class, 'confirm'])->whereNumber('reservation')->middleware('throttle:30,1');
    Route::get('/{reservation}', [ReservationController::class, 'show'])->whereNumber('reservation');
});

Route::get('/api/v1/dashboard/overview', [DashboardOverviewController::class, 'index'])->middleware(['auth:web', RequireCompany::class, PrivateResponse::class]);

Route::get('/', fn () => response()->json(['service' => 'Ben Sbih API', 'health' => '/api/v1/health']));

Route::prefix('api/v1/auth')->middleware(PrivateResponse::class)->group(function (): void {
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('auth.login');
    Route::get('/me', [SessionController::class, 'show'])
        ->middleware(['auth:web', RequireCompany::class])->name('auth.me');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('auth.logout');
});

Route::prefix('api/v1/customers')->middleware(['auth:web', RequireCompany::class, PrivateResponse::class])->group(function (): void {
    Route::get('/', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
    Route::put('/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
    Route::post('/{customer}/documents', [CustomerDocumentController::class, 'store'])->whereNumber('customer')->middleware('throttle:30,1')->name('customers.documents.store');
    Route::get('/{customer}/documents/{document}/content', [CustomerDocumentController::class, 'content'])->whereNumber(['customer', 'document'])->name('customers.documents.content');
});

Route::prefix('api/v1/vehicles')->middleware(['auth:web', RequireCompany::class, PrivateResponse::class])->group(function (): void {
    Route::get('/{vehicle}/pricing', [VehiclePricingController::class, 'vehicle'])->whereNumber('vehicle');
    Route::post('/{vehicle}/pricing', [VehiclePricingController::class, 'updateNormal'])->whereNumber('vehicle')->middleware('throttle:30,1');
    Route::post('/{vehicle}/maintenance/{entry}/payments/{payment}/corrections', [MaintenancePaymentCorrectionController::class, 'store'])->whereNumber(['vehicle', 'entry', 'payment']);

    Route::post('/{vehicle}/maintenance/{entry}/payments/{payment}/cancellations', [MaintenancePaymentCancellationController::class, 'store'])->whereNumber(['vehicle', 'entry', 'payment']);
    Route::post('/{vehicle}/maintenance/{entry}/cancellations', [MaintenanceCancellationController::class, 'store'])->whereNumber(['vehicle', 'entry']);
    Route::post('/{vehicle}/maintenance/{entry}/refunds/{refund}/cancellations', [MaintenanceRefundCancellationController::class, 'store'])->whereNumber(['vehicle', 'entry', 'refund']);
    Route::post('/{vehicle}/maintenance/{entry}/refunds', [MaintenanceRefundController::class, 'store'])->whereNumber(['vehicle', 'entry']);
    Route::get('/{vehicle}/maintenance/{entry}/refunds/{refund}/content', [MaintenanceRefundController::class, 'content'])->whereNumber(['vehicle', 'entry', 'refund']);
    Route::post('/{vehicle}/maintenance/files', [MaintenanceFileController::class, 'store'])->whereNumber('vehicle')->middleware('throttle:30,1');
    Route::get('/{vehicle}/maintenance/{entry}/file', [MaintenanceFileController::class, 'detail'])->whereNumber(['vehicle', 'entry']);
    Route::post('/{vehicle}/maintenance/{entry}/work/{item}/follow-up', [MaintenanceFollowupController::class, 'store'])->whereNumber(['vehicle', 'entry'])->whereUuid('item');
    Route::post('/{vehicle}/maintenance/{entry}/work', [MaintenanceFileController::class, 'saveWork'])->whereNumber(['vehicle', 'entry']);
    Route::post('/{vehicle}/maintenance/{entry}/invoices', [MaintenanceFileController::class, 'invoice'])->whereNumber(['vehicle', 'entry'])->middleware('throttle:30,1');
    Route::get('/{vehicle}/maintenance/{entry}/invoices/{attachment}', [MaintenanceFileController::class, 'content'])->whereNumber(['vehicle', 'entry', 'attachment']);
    Route::get('/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'index'])->whereNumber('vehicle');
    Route::post('/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'store'])->whereNumber('vehicle')->middleware('throttle:30,1');
    Route::get('/{vehicle}/maintenance/{entry}', [VehicleMaintenanceController::class, 'show'])->whereNumber(['vehicle', 'entry']);
    Route::post('/{vehicle}/maintenance/{entry}/payments', [VehicleMaintenanceController::class, 'pay'])->whereNumber(['vehicle', 'entry']);
    Route::post('/{vehicle}/maintenance/{entry}/return', [VehicleMaintenanceController::class, 'release'])->whereNumber(['vehicle', 'entry']);
    Route::get('/{vehicle}/maintenance/{entry}/content', [VehicleMaintenanceController::class, 'content'])->whereNumber(['vehicle', 'entry']);
    Route::get('/{vehicle}/documents/history', [VehicleDocumentController::class, 'history'])->whereNumber('vehicle');
    Route::get('/{vehicle}/documents', [VehicleDocumentController::class, 'index'])->whereNumber('vehicle');
    Route::post('/{vehicle}/documents', [VehicleDocumentController::class, 'store'])->whereNumber('vehicle')->middleware('throttle:30,1');
    Route::get('/{vehicle}/documents/{document}/content', [VehicleDocumentController::class, 'content'])->whereNumber(['vehicle', 'document']);
    Route::get('/', [VehicleController::class, 'index']);
    Route::post('/', [VehicleController::class, 'store']);
    Route::get('/{vehicle}', [VehicleController::class, 'show'])->whereNumber('vehicle');
    Route::put('/{vehicle}', [VehicleController::class, 'update'])->whereNumber('vehicle');
    Route::post('/{vehicle}/odometer', [VehicleOdometerController::class, 'store'])->whereNumber('vehicle');
    Route::post('/{vehicle}/photos', [VehiclePhotoController::class, 'store'])->whereNumber('vehicle')->middleware('throttle:30,1');
    Route::get('/{vehicle}/photos/{document}/content', [VehiclePhotoController::class, 'content'])->whereNumber(['vehicle', 'document']);
});

Route::get('/api/v1/maintenance/overview', [MaintenanceOverviewController::class, 'index'])->middleware(['auth:web', RequireCompany::class, PrivateResponse::class]);

Route::post('/api/v1/loans/{loan}/payments/{payment}/corrections', [VehicleLoanPaymentCorrectionController::class, 'store'])->whereNumber(['loan', 'payment'])->middleware(['auth:web', RequireCompany::class, PrivateResponse::class]);
Route::post('/api/v1/loans/{loan}/payments/{payment}/cancellations', [VehicleLoanPaymentCancellationController::class, 'store'])->whereNumber(['loan', 'payment'])->middleware(['auth:web', RequireCompany::class, PrivateResponse::class]);

Route::prefix('api/v1/loans')->middleware(['auth:web', RequireCompany::class, PrivateResponse::class])->group(function (): void {
    Route::get('/', [VehicleLoanController::class, 'index']);
    Route::post('/', [VehicleLoanController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/{loan}', [VehicleLoanController::class, 'show'])->whereNumber('loan');
    Route::post('/{loan}/cancellations', [VehicleLoanCancellationController::class, 'store'])->whereNumber('loan');
    Route::post('/{loan}/installments/{installment}/payments', [VehicleLoanController::class, 'pay'])->whereNumber(['loan', 'installment']);
    Route::post('/{loan}/documents', [VehicleLoanController::class, 'document'])->whereNumber('loan')->middleware('throttle:30,1');
    Route::get('/{loan}/documents/{document}/content', [VehicleLoanController::class, 'content'])->whereNumber(['loan', 'document']);
});

Route::prefix('api/v1/damages')->middleware(['auth:web', RequireCompany::class, PrivateResponse::class])->group(function (): void {
    Route::get('/', [\App\Http\Controllers\DamageTicketController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\DamageTicketController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/{ticket}', [\App\Http\Controllers\DamageTicketController::class, 'show'])->whereNumber('ticket');
    Route::post('/{ticket}', [\App\Http\Controllers\DamageTicketController::class, 'store'])->whereNumber('ticket')->middleware('throttle:30,1');
});
