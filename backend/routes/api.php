<?php

declare(strict_types=1);

use App\Modules\IAM\Http\Controllers\AuthController;
use App\Modules\IAM\Http\Controllers\AuthorizationAdminController;
use App\Modules\IAM\Http\Controllers\MeController;
use App\Modules\IAM\Http\Controllers\UserAdminController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\MetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public allowlist
|--------------------------------------------------------------------------
|
| /v1/health, /v1/meta and /v1/auth/login are the only unauthenticated
| endpoints. break-glass bootstrap is authenticated but capability-free.
|
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/meta', MetaController::class)->name('meta');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('auth.login');

/*
|--------------------------------------------------------------------------
| Authenticated session endpoints
|--------------------------------------------------------------------------
|
| Every route below carries capability:<code> middleware metadata. The
| capability is code-owned; the second parameter is the bound anchor and the
| optional third is the route parameter that carries the anchor id.
|
*/
Route::middleware(['auth.session', 'csrf.header'])->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->middleware('capability:iam.session.manage,SELF')->name('me.show');
    Route::get('/me/capabilities', [MeController::class, 'capabilities'])->middleware('capability:iam.session.manage,SELF')->name('me.capabilities');

    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('capability:iam.session.manage,SELF')->name('auth.logout');
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->middleware('capability:iam.session.manage,SELF')->name('auth.logout-all');
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('capability:iam.session.manage,SELF')->name('auth.password');

    Route::get('/users', [UserAdminController::class, 'index'])->middleware('capability:iam.user.read,LEGAL_ENTITY')->name('users.index');
    Route::post('/users', [UserAdminController::class, 'store'])->middleware('capability:iam.user.manage,LEGAL_ENTITY')->name('users.store');
    Route::patch('/users/{id}', [UserAdminController::class, 'update'])->middleware('capability:iam.user.manage,LEGAL_ENTITY,id')->name('users.update');
    Route::post('/users/{id}/roles', [UserAdminController::class, 'assignRole'])->middleware('capability:iam.role.manage,LEGAL_ENTITY,id')->name('users.roles.store');

    Route::get('/roles', [AuthorizationAdminController::class, 'roles'])->middleware('capability:iam.role.manage,LEGAL_ENTITY')->name('roles.index');
    Route::get('/capabilities', [AuthorizationAdminController::class, 'capabilities'])->middleware('capability:iam.role.manage,LEGAL_ENTITY')->name('capabilities.index');

    Route::get('/users/{id}/bindings', [AuthorizationAdminController::class, 'bindings'])->middleware('capability:iam.binding.manage,LEGAL_ENTITY,id')->name('bindings.index');
    Route::post('/users/{id}/bindings', [AuthorizationAdminController::class, 'storeBinding'])->middleware('capability:iam.binding.manage,LEGAL_ENTITY,id')->name('bindings.store');
    Route::delete('/bindings/{id}', [AuthorizationAdminController::class, 'destroyBinding'])->middleware('capability:iam.binding.manage,LEGAL_ENTITY,id')->name('bindings.destroy');

    Route::get('/auth/events', [AuthorizationAdminController::class, 'authEvents'])->middleware('capability:iam.audit.read,LEGAL_ENTITY')->name('auth.events');
});
