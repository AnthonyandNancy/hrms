<?php

declare(strict_types=1);

use App\Modules\IAM\Http\Controllers\AuthController;
use App\Modules\IAM\Http\Controllers\AuthorizationAdminController;
use App\Modules\IAM\Http\Controllers\BreakGlassController;
use App\Modules\IAM\Http\Controllers\MeController;
use App\Modules\IAM\Http\Controllers\MfaController;
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
    Route::post('/auth/mfa/totp/enroll', [MfaController::class, 'enrollTotp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.totp.enroll');
    Route::post('/auth/mfa/totp/confirm', [MfaController::class, 'confirmTotp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.totp.confirm');
    Route::post('/auth/mfa/step-up', [MfaController::class, 'stepUp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.step-up');
    Route::post('/auth/webauthn/register/options', [MfaController::class, 'webauthnRegisterOptions'])->middleware('capability:iam.session.manage,SELF')->name('auth.webauthn.register.options');
    Route::post('/auth/webauthn/assert/options', [MfaController::class, 'webauthnAssertOptions'])->middleware('capability:iam.session.manage,SELF')->name('auth.webauthn.assert.options');

    Route::get('/users', [UserAdminController::class, 'index'])->middleware(['mfa.enrolled', 'capability:iam.user.read,LEGAL_ENTITY,legal_entity_id'])->name('users.index');
    Route::post('/users', [UserAdminController::class, 'store'])->middleware(['mfa.enrolled', 'capability:iam.user.manage,LEGAL_ENTITY,legal_entity_id'])->name('users.store');
    Route::patch('/users/{id}', [UserAdminController::class, 'update'])->middleware(['mfa.enrolled', 'capability:iam.user.manage,LEGAL_ENTITY,legal_entity_id'])->name('users.update');
    Route::post('/users/{id}/roles', [UserAdminController::class, 'assignRole'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,LEGAL_ENTITY,legal_entity_id'])->name('users.roles.store');

    Route::get('/roles', [AuthorizationAdminController::class, 'roles'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,LEGAL_ENTITY,legal_entity_id'])->name('roles.index');
    Route::get('/capabilities', [AuthorizationAdminController::class, 'capabilities'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,LEGAL_ENTITY,legal_entity_id'])->name('capabilities.index');

    Route::get('/users/{id}/bindings', [AuthorizationAdminController::class, 'bindings'])->middleware(['mfa.enrolled', 'capability:iam.binding.manage,LEGAL_ENTITY,legal_entity_id'])->name('bindings.index');
    Route::post('/users/{id}/bindings', [AuthorizationAdminController::class, 'storeBinding'])->middleware(['mfa.enrolled', 'capability:iam.binding.manage,LEGAL_ENTITY,legal_entity_id'])->name('bindings.store');
    Route::delete('/bindings/{id}', [AuthorizationAdminController::class, 'destroyBinding'])->middleware(['mfa.enrolled', 'capability:iam.binding.manage,LEGAL_ENTITY,legal_entity_id'])->name('bindings.destroy');

    Route::post('/auth/break-glass', [BreakGlassController::class, 'activate'])->middleware('capability:iam.session.manage,SELF')->name('auth.break-glass.activate');
    Route::post('/auth/break-glass/{id}/revoke', [BreakGlassController::class, 'revoke'])->middleware('capability:iam.session.manage,SELF')->name('auth.break-glass.revoke');

    Route::get('/auth/events', [AuthorizationAdminController::class, 'authEvents'])->middleware(['mfa.enrolled', 'capability:iam.audit.read,LEGAL_ENTITY,legal_entity_id'])->name('auth.events');
});
