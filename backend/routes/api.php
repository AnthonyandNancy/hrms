<?php

declare(strict_types=1);

use App\Modules\Organization\Http\Controllers\OrgUnitController;
use App\Modules\People\Http\Controllers\EmployeeController;
use App\Modules\People\Http\Controllers\EmploymentController;
use App\Modules\People\Http\Controllers\PersonController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\MetaController;
use App\Modules\Platform\Http\Middleware\IdempotentCommand;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public allowlist for capability:audit
|--------------------------------------------------------------------------
|
| Health/meta are intentionally public. Every other /v1 route carries
| capability:<code> middleware metadata. The IAM Task 2 middleware consumes
| this metadata; until that branch lands the Authorizer remains fail-closed.
|
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/meta', MetaController::class)->name('meta');

Route::prefix('employees')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])
        ->middleware('capability:people.employee.read')
        ->name('employees.index');
    Route::get('/{employment_id}', [EmployeeController::class, 'show'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.employee.read')
        ->name('employees.show');
});

Route::prefix('employments')->group(function (): void {
    Route::post('/', [EmploymentController::class, 'store'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:people.employee.manage')
        ->name('employments.store');
    Route::post('/{employment_id}/assignments', [EmploymentController::class, 'storeAssignment'])
        ->whereNumber('employment_id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:people.assignment.manage')
        ->name('employments.assignments.store');
    Route::post('/{employment_id}/terms', [EmploymentController::class, 'storeTerms'])
        ->whereNumber('employment_id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:people.contract.manage')
        ->name('employments.terms.store');
    Route::post('/{employment_id}/documents', [EmploymentController::class, 'storeDocument'])
        ->whereNumber('employment_id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:people.contract.manage')
        ->name('employments.documents.store');
    Route::get('/{employment_id}/timeline', [EmployeeController::class, 'timeline'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.employee.read')
        ->name('employments.timeline');
    Route::post('/{employment_id}/transfer', [EmploymentController::class, 'transfer'])
        ->whereNumber('employment_id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:people.employee.manage')
        ->name('employments.transfer');
});

Route::post('/persons', [PersonController::class, 'store'])
    ->middleware(IdempotentCommand::class)
    ->middleware('capability:people.employee.manage')
    ->name('persons.store');

Route::get('/documents/{document_id}/download', [EmploymentController::class, 'downloadDocument'])
    ->whereNumber('document_id')
    ->middleware('capability:people.document.download')
    ->name('documents.download');

Route::prefix('org-units')->group(function (): void {
    Route::get('/tree', [OrgUnitController::class, 'tree'])
        ->middleware(['capability:org.tree.read', 'capability:org.unit.read'])
        ->name('org-units.tree');
    Route::post('/', [OrgUnitController::class, 'store'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:org.unit.manage')
        ->name('org-units.store');
    Route::post('/{id}/move', [OrgUnitController::class, 'move'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:org.unit.manage')
        ->name('org-units.move');
    Route::get('/{id}/impact', [OrgUnitController::class, 'impact'])
        ->whereNumber('id')
        ->middleware('capability:org.unit.manage')
        ->name('org-units.impact');
});
