<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\NoticeController;
use App\Modules\Engagement\Http\Controllers\NotificationController;
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
use App\Modules\Platform\Http\Controllers\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\LivenessController;
use App\Modules\Platform\Http\Controllers\MetaController;
use App\Modules\Platform\Http\Controllers\ReadinessController;
use App\Modules\Platform\Http\Middleware\IdempotentCommand;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\ReportingController;
use App\Modules\Workflow\Http\Controllers\CaseController;
use App\Modules\Workflow\Http\Controllers\DecisionController;
use App\Modules\Workflow\Http\Controllers\DelegationController;
use App\Modules\Workflow\Http\Controllers\EffectController;
use App\Modules\Workflow\Http\Controllers\TaskController;
use App\Modules\Workflow\Http\Controllers\TemplateController;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Shared\Authorization\ResolveLegalEntityContext;
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
| Engagement: notices and in-app notifications
|--------------------------------------------------------------------------
*/
Route::prefix('engagement')->middleware('auth.session')->group(function (): void {
    Route::get('/notices', [NoticeController::class, 'index'])
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.index');
    Route::get('/notices/{notice}', [NoticeController::class, 'show'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.show');
    Route::post('/notices', [NoticeController::class, 'store'])
        ->middleware([ResolveLegalEntityContext::class, 'capability:engagement.notice.manage,LEGAL_ENTITY,legal_entity_id'])
        ->name('engagement.notices.store');
    Route::post('/notices/{notice}/acknowledge', [NoticeController::class, 'acknowledge'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.acknowledge');
    Route::post('/notices/{notice}/retract', [NoticeController::class, 'retract'])
        ->whereNumber('notice')
        ->middleware([ResolveLegalEntityContext::class, 'capability:engagement.notice.manage,LEGAL_ENTITY,legal_entity_id'])
        ->name('engagement.notices.retract');

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('capability:engagement.notification.read')
        ->name('engagement.notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification')
        ->middleware('capability:engagement.notification.read')
        ->name('engagement.notifications.read');
});

/*
|--------------------------------------------------------------------------
| Reporting: dashboard, definitions, runs and async exports
|--------------------------------------------------------------------------
*/
Route::prefix('reporting')->middleware('auth.session')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'show'])
        ->middleware('capability:reporting.dashboard.read')
        ->name('reporting.dashboard.show');
    Route::get('/dashboard/metrics/{metric}', [DashboardController::class, 'metric'])
        ->middleware('capability:reporting.dashboard.read')
        ->name('reporting.dashboard.metric');
    Route::get('/definitions', [ReportingController::class, 'definitions'])
        ->middleware('capability:reporting.dashboard.read')
        ->name('reporting.definitions.index');
    Route::post('/run', [ReportingController::class, 'run'])
        ->middleware('capability:reporting.dashboard.read')
        ->name('reporting.run');
    Route::post('/exports', [ReportingController::class, 'createExport'])
        ->middleware(['throttle:10,1', ResolveLegalEntityContext::class, 'capability:reporting.export,LEGAL_ENTITY,legal_entity_id'])
        ->name('reporting.exports.store');
    Route::get('/exports/{jobId}/download', [ReportingController::class, 'download'])
        ->whereNumber('jobId')
        ->middleware('capability:reporting.export')
        ->name('reporting.exports.download');
});
