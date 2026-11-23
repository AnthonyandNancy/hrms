<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\LivenessController;
use App\Modules\Platform\Http\Controllers\MetaController;
use App\Modules\Platform\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public allowlist for capability:audit
|--------------------------------------------------------------------------
|
| Every route in this file is under /v1. A route that is not explicitly
| public must carry capability:<code> middleware metadata. Task 1 exposes
| only liveness/meta endpoints; Task 2+ adds authenticated modules here.
|
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/health/live', LivenessController::class)->name('health.live');
Route::get('/health/ready', ReadinessController::class)->name('health.ready');
Route::get('/meta', MetaController::class)->name('meta');

Route::middleware('capability:platform.feature.manage')->group(function (): void {
    Route::get('/platform/features', [FeatureFlagController::class, 'index'])->name('platform.features.index');
    Route::put('/platform/features/{flag}', [FeatureFlagController::class, 'update'])->name('platform.features.update');
});
