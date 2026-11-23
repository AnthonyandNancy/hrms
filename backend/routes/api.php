<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\MetaController;
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
Route::get('/meta', MetaController::class)->name('meta');
