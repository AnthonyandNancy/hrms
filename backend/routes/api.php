<?php

declare(strict_types=1);

use App\Modules\IAM\Http\Controllers\AuthController;
use App\Modules\IAM\Http\Controllers\AuthorizationAdminController;
use App\Modules\IAM\Http\Controllers\BreakGlassController;
use App\Modules\IAM\Http\Controllers\MeController;
use App\Modules\IAM\Http\Controllers\MfaController;
use App\Modules\IAM\Http\Controllers\UserAdminController;
use App\Modules\Organization\Http\Controllers\OrgUnitController;
use App\Modules\People\Http\Controllers\EmployeeController;
use App\Modules\People\Http\Controllers\EmploymentController;
use App\Modules\People\Http\Controllers\PersonController;
use App\Modules\Payroll\Http\Controllers\PayrollCatalogController;
use App\Modules\Payroll\Http\Controllers\PayrollStatutoryParameterController;
use App\Modules\Payroll\Http\Controllers\PayrollPayslipController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Platform\Http\Controllers\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\LivenessController;
use App\Modules\Platform\Http\Controllers\MetaController;
use App\Modules\Platform\Http\Controllers\ReadinessController;
use App\Modules\Platform\Http\Middleware\IdempotentCommand;
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
| Public allowlist
|--------------------------------------------------------------------------
|
| Health/meta are intentionally public. Every other /v1 route carries
| capability:<code> middleware metadata. The IAM Task 2 middleware consumes
| this metadata; until that branch lands the Authorizer remains fail-closed.
|
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/health/live', LivenessController::class)->name('health.live');
Route::get('/health/ready', ReadinessController::class)->name('health.ready');
Route::get('/meta', MetaController::class)->name('meta');

/*
|--------------------------------------------------------------------------
| IAM authentication, sessions, MFA and administration
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('auth.login');

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
    Route::post('/users', [UserAdminController::class, 'store'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.user.manage,LEGAL_ENTITY,legal_entity_id'])->name('users.store');
    Route::patch('/users/{id}', [UserAdminController::class, 'update'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.user.manage,LEGAL_ENTITY,legal_entity_id'])->name('users.update');
    Route::post('/users/{id}/roles', [UserAdminController::class, 'assignRole'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,LEGAL_ENTITY,legal_entity_id'])->name('users.roles.store');

    Route::get('/roles', [AuthorizationAdminController::class, 'roles'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,LEGAL_ENTITY,legal_entity_id'])->name('roles.index');
    Route::get('/capabilities', [AuthorizationAdminController::class, 'capabilities'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,LEGAL_ENTITY,legal_entity_id'])->name('capabilities.index');

    Route::get('/users/{id}/bindings', [AuthorizationAdminController::class, 'bindings'])->middleware(['mfa.enrolled', 'capability:iam.binding.manage,LEGAL_ENTITY,legal_entity_id'])->name('bindings.index');
    Route::post('/users/{id}/bindings', [AuthorizationAdminController::class, 'storeBinding'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.binding.manage,LEGAL_ENTITY,legal_entity_id'])->name('bindings.store');
    Route::delete('/bindings/{id}', [AuthorizationAdminController::class, 'destroyBinding'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.binding.manage,LEGAL_ENTITY,legal_entity_id'])->name('bindings.destroy');

    Route::post('/auth/break-glass', [BreakGlassController::class, 'activate'])->middleware(['mfa.step-up', 'capability:iam.session.manage,SELF'])->name('auth.break-glass.activate');
    Route::post('/auth/break-glass/{id}/revoke', [BreakGlassController::class, 'revoke'])->middleware('capability:iam.session.manage,SELF')->name('auth.break-glass.revoke');

    Route::get('/auth/events', [AuthorizationAdminController::class, 'authEvents'])->middleware(['mfa.enrolled', 'capability:iam.audit.read,LEGAL_ENTITY,legal_entity_id'])->name('auth.events');
});

Route::prefix('employees')->middleware('auth.session')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])
        ->middleware('capability:people.employee.read')
        ->name('employees.index');
    Route::get('/{employment_id}', [EmployeeController::class, 'show'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.employee.read')
        ->name('employees.show');
});

Route::prefix('employments')->middleware('auth.session')->group(function (): void {
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
    ->middleware('auth.session')
    ->middleware(IdempotentCommand::class)
    ->middleware('capability:people.employee.manage')
    ->name('persons.store');

Route::get('/documents/{document_id}/download', [EmploymentController::class, 'downloadDocument'])
    ->middleware('auth.session')
    ->whereNumber('document_id')
    ->middleware('capability:people.document.download')
    ->name('documents.download');

Route::prefix('org-units')->middleware('auth.session')->group(function (): void {
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

Route::middleware(['auth.session', 'capability:platform.feature.manage'])->group(function (): void {
    Route::get('/platform/features', [FeatureFlagController::class, 'index'])->name('platform.features.index');
    Route::put('/platform/features/{flag}', [FeatureFlagController::class, 'update'])->name('platform.features.update');
});

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

/*
|--------------------------------------------------------------------------
| Payroll
|--------------------------------------------------------------------------
|
| Every route is capability-guarded. Run-scoped mutations are anchored to the
| run so a specialist cannot touch a run outside their membership. Payslip
| self-read is anchored SELF; detail is scoped again in the controller and
| returns 404 for another employee.
|
*/
Route::prefix('payroll')->middleware(['auth.session', 'csrf.header'])->group(function (): void {
    Route::get('/items', [PayrollCatalogController::class, 'items'])
        ->middleware('capability:payroll.run.read,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.items.index');
    Route::post('/items', [PayrollCatalogController::class, 'storeItem'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.items.store');
    Route::get('/schemes', [PayrollCatalogController::class, 'schemes'])
        ->middleware('capability:payroll.run.read,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.schemes.index');
    Route::post('/schemes', [PayrollCatalogController::class, 'storeScheme'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.schemes.store');

    Route::get('/runs', [PayrollRunController::class, 'index'])
        ->middleware('capability:payroll.run.read,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.runs.index');
    Route::post('/runs', [PayrollRunController::class, 'store'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.runs.store');
    Route::get('/runs/{id}', [PayrollRunController::class, 'show'])
        ->whereNumber('id')
        ->middleware('capability:payroll.run.read,PAYROLL_RUN')
        ->name('payroll.runs.show');
    Route::get('/runs/{id}/employees', [PayrollRunController::class, 'employees'])
        ->whereNumber('id')
        ->middleware('capability:payroll.run.read,PAYROLL_RUN')
        ->name('payroll.runs.employees');
    Route::get('/runs/{id}/reconciliation', [PayrollRunController::class, 'reconciliation'])
        ->whereNumber('id')
        ->middleware('capability:payroll.run.read,PAYROLL_RUN')
        ->name('payroll.runs.reconciliation');

    Route::post('/runs/{id}/lock-inputs', [PayrollRunController::class, 'lockInputs'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,PAYROLL_RUN')
        ->name('payroll.runs.lock-inputs');
    Route::post('/runs/{id}/calculate', [PayrollRunController::class, 'calculate'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,PAYROLL_RUN')
        ->name('payroll.runs.calculate');
    Route::post('/runs/{id}/review', [PayrollRunController::class, 'review'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.review,PAYROLL_RUN'])
        ->name('payroll.runs.review');
    Route::post('/runs/{id}/approve', [PayrollRunController::class, 'approve'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.approve,PAYROLL_RUN'])
        ->name('payroll.runs.approve');
    Route::post('/runs/{id}/publish', [PayrollRunController::class, 'publish'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.publish,PAYROLL_RUN'])
        ->name('payroll.runs.publish');
    Route::post('/runs/{id}/mark-paid', [PayrollRunController::class, 'markPaid'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.close,PAYROLL_RUN'])
        ->name('payroll.runs.mark-paid');
    Route::post('/runs/{id}/close', [PayrollRunController::class, 'close'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.close,PAYROLL_RUN'])
        ->name('payroll.runs.close');
    Route::post('/runs/{id}/cancel', [PayrollRunController::class, 'cancel'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,PAYROLL_RUN')
        ->name('payroll.runs.cancel');
    Route::post('/runs/{id}/reopen', [PayrollRunController::class, 'reopen'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.reopen,PAYROLL_RUN'])
        ->name('payroll.runs.reopen');
    Route::post('/runs/{id}/corrections', [PayrollRunController::class, 'correction'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.run.correct,PAYROLL_RUN'])
        ->name('payroll.runs.corrections.store');

    Route::get('/runs/{id}/exports/bank', [PayrollRunController::class, 'exportBank'])
        ->whereNumber('id')
        ->middleware(['mfa.step-up', 'capability:payroll.export,PAYROLL_RUN'])
        ->name('payroll.runs.exports.bank');
    Route::get('/runs/{id}/exports/tax', [PayrollRunController::class, 'exportTax'])
        ->whereNumber('id')
        ->middleware(['mfa.step-up', 'capability:payroll.export,PAYROLL_RUN'])
        ->name('payroll.runs.exports.tax');

    Route::get('/payslips', [PayrollPayslipController::class, 'mine'])
        ->middleware('capability:payroll.payslip.read.self,SELF')
        ->name('payroll.payslips.mine');
    Route::get('/payslips/{id}', [PayrollPayslipController::class, 'show'])
        ->whereNumber('id')
        ->middleware('capability:payroll.payslip.read.self,SELF')
        ->name('payroll.payslips.show');

    Route::get('/statutory-parameters', [PayrollStatutoryParameterController::class, 'index'])
        ->middleware('capability:payroll.parameters.read,LEGAL_ENTITY,legal_entity_id')
        ->name('payroll.parameters.index');
    Route::post('/statutory-parameters', [PayrollStatutoryParameterController::class, 'store'])
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.parameters.manage,LEGAL_ENTITY,legal_entity_id'])
        ->name('payroll.parameters.store');
});

