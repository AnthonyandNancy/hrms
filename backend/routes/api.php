<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\MetaController;
use App\Modules\Workflow\Http\Controllers\CaseController;
use App\Modules\Workflow\Http\Controllers\DecisionController;
use App\Modules\Workflow\Http\Controllers\DelegationController;
use App\Modules\Workflow\Http\Controllers\EffectController;
use App\Modules\Workflow\Http\Controllers\TaskController;
use App\Modules\Workflow\Http\Controllers\TemplateController;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
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

Route::middleware(WorkflowPrincipalContext::class)->prefix('cases')->group(function (): void {
    Route::get('/', [CaseController::class, 'index'])
        ->middleware('capability:workflow.case.read')
        ->name('workflow.cases.index');
    Route::post('/', [CaseController::class, 'store'])
        ->middleware('capability:workflow.case.create')
        ->name('workflow.cases.store');
    Route::get('/{id}', [CaseController::class, 'show'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.read')
        ->name('workflow.cases.show');
    Route::get('/{id}/timeline', [CaseController::class, 'timeline'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.read')
        ->name('workflow.cases.timeline');
    Route::post('/{id}/submit', [CaseController::class, 'submit'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.create')
        ->name('workflow.cases.submit');
    Route::post('/{id}/withdraw', [CaseController::class, 'withdraw'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.create')
        ->name('workflow.cases.withdraw');
    Route::post('/{caseId}/decisions', [DecisionController::class, 'store'])
        ->whereNumber('caseId')
        ->middleware('capability:workflow.case.decide')
        ->name('workflow.cases.decide');
});

Route::prefix('effects')->middleware(WorkflowPrincipalContext::class)->group(function (): void {
    Route::post('/{id}/retry', [EffectController::class, 'retry'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.admin')
        ->name('workflow.effects.retry');
});

Route::get('/tasks/my', [TaskController::class, 'my'])
    ->middleware([WorkflowPrincipalContext::class, 'capability:workflow.case.read'])
    ->name('workflow.tasks.my');

Route::prefix('workflow/templates')->middleware(WorkflowPrincipalContext::class)->group(function (): void {
    Route::get('/', [TemplateController::class, 'index'])
        ->middleware('capability:workflow.case.read')
        ->name('workflow.templates.index');
    Route::post('/', [TemplateController::class, 'store'])
        ->middleware('capability:workflow.case.admin')
        ->name('workflow.templates.store');
});

Route::middleware(WorkflowPrincipalContext::class)->group(function (): void {
    Route::post('/delegations', [DelegationController::class, 'store'])
        ->middleware('capability:workflow.case.decide')
        ->name('workflow.delegations.store');
    Route::delete('/delegations/{id}', [DelegationController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.decide')
        ->name('workflow.delegations.destroy');
});
