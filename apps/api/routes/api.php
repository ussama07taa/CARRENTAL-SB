<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicWebsiteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('public')->group(function (): void {
    Route::get('/vehicles/{vehicle}/calendar', [PublicWebsiteController::class, 'calendar'])->whereNumber('vehicle')->middleware('throttle:60,1');
    Route::get('/vehicles', [PublicWebsiteController::class, 'catalog'])->middleware('throttle:60,1');
    Route::get('/vehicles/{vehicle}', [PublicWebsiteController::class, 'show'])->whereNumber('vehicle')->middleware('throttle:60,1');
    Route::get('/photo/{vehicle}/{slot?}', [PublicWebsiteController::class, 'photo'])->whereNumber('vehicle')->middleware('throttle:60,1');
    Route::post('/requests', [PublicWebsiteController::class, 'store'])->middleware('throttle:5,1');
    Route::post('/requests/lookup', [PublicWebsiteController::class, 'lookup'])->middleware('throttle:10,1');
});
