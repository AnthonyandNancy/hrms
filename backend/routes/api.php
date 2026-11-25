<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\NoticeController;
use App\Modules\Engagement\Http\Controllers\NotificationController;
use App\Modules\IAM\Http\Controllers\AccessReviewController;
use App\Modules\IAM\Http\Controllers\AuthController;
use App\Modules\IAM\Http\Controllers\AuthorizationAdminController;
use App\Modules\IAM\Http\Controllers\BreakGlassController;
use App\Modules\IAM\Http\Controllers\MeController;
use App\Modules\IAM\Http\Controllers\MfaController;
use App\Modules\IAM\Http\Controllers\UserAdminController;
use App\Modules\IAM\Http\Controllers\WorkspaceController;
use App\Modules\Lifecycle\Http\Controllers\LifecycleController;
use App\Modules\Organization\Http\Controllers\DepartmentController;
use App\Modules\Organization\Http\Controllers\OrgUnitController;
use App\Modules\Organization\Http\Controllers\PositionController;
use App\Modules\Payroll\Http\Controllers\PayrollCatalogController;
use App\Modules\Payroll\Http\Controllers\PayrollPayslipController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayrollStatutoryParameterController;
use App\Modules\People\Http\Controllers\EmployeeController;
use App\Modules\People\Http\Controllers\EmployeeProfileController;
use App\Modules\People\Http\Controllers\EmploymentContractController;
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
use App\Modules\Talent\Http\Controllers\PerformanceController;
use App\Modules\Talent\Http\Controllers\PerformanceTemplateController;
use App\Modules\Talent\Http\Controllers\RecruitmentController;
use App\Modules\Talent\Http\Controllers\TrainingController;
use App\Modules\Time\Http\Controllers\AttendanceClosingController;
use App\Modules\Time\Http\Controllers\AttendanceController;
use App\Modules\Time\Http\Controllers\LeaveController;
use App\Modules\Time\Http\Controllers\OvertimeController;
use App\Modules\Time\Http\Controllers\TimeCatalogController;
use App\Modules\Time\Http\Controllers\TravelController;
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
Route::post('/auth/mfa/challenge', [MfaController::class, 'completeChallenge'])->middleware('throttle:5,1')->name('auth.mfa.challenge');

Route::middleware(['auth.session', 'csrf.header'])->group(function (): void {
    Route::get('/me/workspace', [WorkspaceController::class, 'show'])->middleware('capability:iam.session.manage,SELF')->name('me.workspace');
    Route::get('/me', [MeController::class, 'show'])->middleware('capability:iam.session.manage,SELF')->name('me.show');
    Route::get('/me/capabilities', [MeController::class, 'capabilities'])->middleware('capability:iam.session.manage,SELF')->name('me.capabilities');
    Route::get('/me/permissions', [MeController::class, 'permissions'])->middleware('capability:iam.session.manage,SELF')->name('me.permissions');

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

    Route::get('/access-review', [AccessReviewController::class, 'show'])->middleware(['mfa.enrolled', 'capability:iam.audit.read,LEGAL_ENTITY,legal_entity_id'])->name('access-review.show');

    Route::get('/auth/events', [AuthorizationAdminController::class, 'authEvents'])->middleware(['mfa.enrolled', 'capability:iam.audit.read,LEGAL_ENTITY,legal_entity_id'])->name('auth.events');
});

Route::prefix('employees')->middleware('auth.session')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])
        ->middleware('capability:people.employee.read,LEGAL_ENTITY,legal_entity_id')
        ->name('employees.index');
    Route::get('/{employment_id}', [EmployeeController::class, 'show'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.employee.read,LEGAL_ENTITY,legal_entity_id')
        ->name('employees.show');
});

Route::prefix('employees/{employmentId}')->middleware('auth.session')->group(function (): void {
    foreach (['education', 'experiences', 'contacts', 'certificates', 'change_requests', 'imports'] as $profileResource) {
        Route::get('/'.$profileResource, [EmployeeProfileController::class, 'index'])
            ->whereNumber('employmentId')
            ->defaults('profileResource', $profileResource)
            ->middleware('capability:people.employee.read')
            ->name('employees.profile.'.$profileResource.'.index');
        Route::post('/'.$profileResource, [EmployeeProfileController::class, 'store'])
            ->whereNumber('employmentId')
            ->defaults('profileResource', $profileResource)
            ->middleware('capability:people.employee.manage')
            ->name('employees.profile.'.$profileResource.'.store');
        Route::patch('/'.$profileResource.'/{id}', [EmployeeProfileController::class, 'update'])
            ->whereNumber('employmentId')
            ->whereNumber('id')
            ->defaults('profileResource', $profileResource)
            ->middleware('capability:people.employee.manage')
            ->name('employees.profile.'.$profileResource.'.update');
    }

    Route::post('/change_requests/{id}/submit', [EmployeeProfileController::class, 'submit'])
        ->whereNumber('employmentId')
        ->whereNumber('id')
        ->middleware('capability:people.employee.manage')
        ->name('employees.profile.change_requests.submit');
});

Route::get('/employments/contracts/expiring', [EmploymentContractController::class, 'expiring'])
    ->middleware('auth.session')
    ->middleware('capability:people.contract.read')
    ->name('employments.contracts.expiring');

Route::prefix('employments/{employmentId}/contracts')->middleware('auth.session')->group(function (): void {
    Route::get('/', [EmploymentContractController::class, 'index'])
        ->whereNumber('employmentId')
        ->middleware('capability:people.contract.read')
        ->name('employments.contracts.index');
    Route::post('/', [EmploymentContractController::class, 'store'])
        ->whereNumber('employmentId')
        ->middleware(['mfa.enrolled', 'capability:people.contract.manage'])
        ->name('employments.contracts.store');
    Route::get('/{id}', [EmploymentContractController::class, 'show'])
        ->whereNumber('employmentId')
        ->whereNumber('id')
        ->middleware('capability:people.contract.read')
        ->name('employments.contracts.show');
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

Route::prefix('departments')->middleware('auth.session')->group(function (): void {
    Route::get('/', [DepartmentController::class, 'index'])
        ->middleware('capability:org.unit.read')
        ->name('departments.index');
    Route::get('/staffing', [DepartmentController::class, 'staffing'])
        ->middleware('capability:org.unit.read')
        ->name('departments.staffing');
    Route::get('/{id}/members', [DepartmentController::class, 'members'])
        ->whereNumber('id')
        ->middleware('capability:org.unit.read')
        ->name('departments.members.index');
    Route::post('/{id}/members', [DepartmentController::class, 'addMember'])
        ->whereNumber('id')
        ->middleware(['mfa.enrolled', 'capability:org.unit.manage'])
        ->name('departments.members.store');
});

Route::prefix('positions')->middleware('auth.session')->group(function (): void {
    Route::get('/', [PositionController::class, 'index'])
        ->middleware('capability:org.position.read')
        ->name('positions.index');
    Route::post('/', [PositionController::class, 'store'])
        ->middleware(['mfa.enrolled', 'capability:org.position.manage'])
        ->name('positions.store');
    Route::get('/{id}', [PositionController::class, 'show'])
        ->whereNumber('id')
        ->middleware('capability:org.position.read')
        ->name('positions.show');
    Route::patch('/{id}', [PositionController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['mfa.enrolled', 'capability:org.position.manage'])
        ->name('positions.update');
});

Route::middleware(['auth.session', 'capability:platform.feature.manage'])->group(function (): void {
    Route::get('/platform/features', [FeatureFlagController::class, 'index'])->name('platform.features.index');
    Route::put('/platform/features/{flag}', [FeatureFlagController::class, 'update'])->name('platform.features.update');
});

Route::middleware(['auth.session', WorkflowPrincipalContext::class])->prefix('cases')->group(function (): void {
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

Route::prefix('effects')->middleware(['auth.session', WorkflowPrincipalContext::class])->group(function (): void {
    Route::post('/{id}/retry', [EffectController::class, 'retry'])
        ->whereNumber('id')
        ->middleware('capability:workflow.case.admin')
        ->name('workflow.effects.retry');
});

Route::get('/tasks/my', [TaskController::class, 'my'])
    ->middleware(['auth.session', WorkflowPrincipalContext::class, 'capability:workflow.case.read'])
    ->name('workflow.tasks.my');

Route::prefix('workflow/templates')->middleware(['auth.session', WorkflowPrincipalContext::class])->group(function (): void {
    Route::get('/', [TemplateController::class, 'index'])
        ->middleware('capability:workflow.case.read')
        ->name('workflow.templates.index');
    Route::post('/', [TemplateController::class, 'store'])
        ->middleware('capability:workflow.case.admin')
        ->name('workflow.templates.store');
});

Route::middleware(['auth.session', WorkflowPrincipalContext::class])->group(function (): void {
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
| Time, attendance and leave
|--------------------------------------------------------------------------
|
| Every route requires authentication, a capability and an employment or
| legal-entity scope resolved from the IAM-backed TimeActor. The frontend
| contract (`/v1/time/...`) and the brief aliases (`/v1/leave/...`) share the
| same controllers so there is one implementation and one negative-test matrix.
|
*/
Route::prefix('time')->middleware('auth.session')->group(function (): void {
    Route::post('/punches/import', [AttendanceController::class, 'importPunches'])
        ->middleware('capability:time.punch.import')
        ->name('time.punches.import');

    Route::get('/attendance', [AttendanceController::class, 'month'])
        ->middleware('capability:time.attendance.read')
        ->name('time.attendance.month');

    Route::get('/attendance/{employment_id}', [AttendanceController::class, 'days'])
        ->whereNumber('employment_id')
        ->middleware('capability:time.attendance.read')
        ->name('time.attendance.days');

    Route::post('/attendance/corrections', [AttendanceController::class, 'correct'])
        ->middleware('capability:time.attendance.correct.request')
        ->name('time.attendance.corrections.store');

    Route::post('/attendance/closings', [AttendanceClosingController::class, 'close'])
        ->middleware('capability:time.attendance.manage')
        ->name('time.attendance.closings.store');
    Route::post('/attendance/closings/{id}/reopen', [AttendanceClosingController::class, 'reopen'])
        ->whereNumber('id')
        ->middleware('capability:time.attendance.reopen')
        ->name('time.attendance.closings.reopen');
    Route::post('/attendance/recalculate', [AttendanceClosingController::class, 'recalculate'])
        ->middleware('capability:time.attendance.manage')
        ->name('time.attendance.recalculate');

    Route::get('/calendars/{legal_entity_id}', [TimeCatalogController::class, 'calendar'])
        ->whereNumber('legal_entity_id')
        ->middleware('capability:time.calendar.read')
        ->name('time.calendars.show');

    Route::get('/shifts', [TimeCatalogController::class, 'shifts'])
        ->middleware('capability:time.shift.read')
        ->name('time.shifts.index');

    Route::get('/leave/types', [TimeCatalogController::class, 'leaveTypes'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.types.index');
    Route::get('/leave/policies', [TimeCatalogController::class, 'leavePolicies'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.policies.index');
    Route::get('/overtime/records', [TimeCatalogController::class, 'overtimeRecords'])
        ->middleware('capability:time.overtime.read')
        ->name('time.overtime.records.index');
    Route::get('/overtime/policies', [TimeCatalogController::class, 'overtimePolicies'])
        ->middleware('capability:time.overtime.read')
        ->name('time.overtime.policies.show');
    Route::get('/leave/balances', [LeaveController::class, 'balances'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.balances');

    Route::get('/leave/requests', [LeaveController::class, 'requests'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.requests.index');

    Route::post('/leave/preview', [LeaveController::class, 'preview'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.preview');

    Route::post('/leave/requests', [LeaveController::class, 'request'])
        ->middleware('capability:time.leave.request')
        ->name('time.leave.requests.store');

    Route::post('/leave/requests/{caseId}/cancel', [LeaveController::class, 'cancel'])
        ->whereNumber('caseId')
        ->middleware('capability:time.leave.request.cancel')
        ->name('time.leave.requests.cancel');

    Route::get('/leave/ledger', [LeaveController::class, 'ledger'])
        ->middleware('capability:time.leave.ledger.read')
        ->name('time.leave.ledger');
});

Route::prefix('travel')->middleware('auth.session')->group(function (): void {
    Route::get('/requests', [TravelController::class, 'index'])
        ->middleware('capability:time.travel.read')
        ->name('time.travel.requests.index');
    Route::post('/requests', [TravelController::class, 'store'])
        ->middleware('capability:time.travel.request')
        ->name('time.travel.requests.store');
    Route::post('/requests/{id}/cancel', [TravelController::class, 'cancel'])
        ->whereNumber('id')
        ->middleware('capability:time.travel.request')
        ->name('time.travel.requests.cancel');
});

Route::prefix('overtime')->middleware('auth.session')->group(function (): void {
    Route::post('/records/{recordId}/settlements', [OvertimeController::class, 'settle'])
        ->whereNumber('recordId')
        ->middleware('capability:time.overtime.settle')
        ->name('time.overtime.settlements.store');
});

Route::prefix('leave')->middleware('auth.session')->group(function (): void {
    Route::post('/requests', [LeaveController::class, 'request'])
        ->middleware('capability:time.leave.request')
        ->name('leave.requests.store');

    Route::post('/requests/{caseId}/cancel', [LeaveController::class, 'cancel'])
        ->whereNumber('caseId')
        ->middleware('capability:time.leave.request.cancel')
        ->name('leave.requests.cancel');

    Route::get('/balances/{employment_id}', [LeaveController::class, 'balances'])
        ->whereNumber('employment_id')
        ->middleware('capability:time.leave.read')
        ->name('leave.balances.show');

    Route::get('/ledger/{employment_id}', [LeaveController::class, 'ledger'])
        ->whereNumber('employment_id')
        ->middleware('capability:time.leave.ledger.read')
        ->name('leave.ledger.show');
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
| Lifecycle command surface
|--------------------------------------------------------------------------
|
| Every command creates or advances a Workflow Case; no controller writes a
| People fact directly. Mutations require an Idempotency-Key and are tenant and
| capability guarded.
|
*/
Route::prefix('lifecycle')->middleware(['auth.session'])->group(function (): void {
    Route::post('/onboarding', [LifecycleController::class, 'onboarding'])
        ->middleware('capability:lifecycle.onboarding.manage,LEGAL_ENTITY,legal_entity_id')
        ->name('lifecycle.onboarding.store');
    Route::post('/probation-confirmations', [LifecycleController::class, 'probationConfirmation'])
        ->middleware('capability:lifecycle.probation.manage')
        ->name('lifecycle.probation.store');
    Route::post('/transfers', [LifecycleController::class, 'transfer'])
        ->middleware('capability:lifecycle.transfer.manage,LEGAL_ENTITY,legal_entity_id')
        ->name('lifecycle.transfers.store');
    Route::post('/salary-changes', [LifecycleController::class, 'salaryChange'])
        ->middleware('capability:lifecycle.terms.manage')
        ->name('lifecycle.salary-changes.store');
    Route::post('/offboardings', [LifecycleController::class, 'offboarding'])
        ->middleware('capability:lifecycle.offboard.manage')
        ->name('lifecycle.offboardings.store');
    Route::post('/contract-renewals', [LifecycleController::class, 'contractRenewal'])
        ->middleware('capability:lifecycle.contract.manage')
        ->name('lifecycle.contract-renewals.store');
    Route::post('/rehires', [LifecycleController::class, 'rehire'])
        ->middleware('capability:lifecycle.rehire.manage,LEGAL_ENTITY,legal_entity_id')
        ->name('lifecycle.rehires.store');
    Route::get('/cases', [LifecycleController::class, 'cases'])
        ->middleware('capability:lifecycle.case.read')
        ->name('lifecycle.cases.index');

    Route::get('/cases/{caseId}', [LifecycleController::class, 'showCase'])
        ->whereNumber('caseId')
        ->middleware('capability:lifecycle.case.read')
        ->name('lifecycle.cases.show');
});

Route::get('/employees/{employment_id}/onboarding-checklist', [LifecycleController::class, 'onboardingChecklist'])
    ->whereNumber('employment_id')
    ->middleware(['auth.session', 'capability:lifecycle.case.read'])
    ->name('lifecycle.onboarding-checklist.index');

Route::patch('/onboarding-tasks/{id}', [LifecycleController::class, 'updateChecklistTask'])
    ->whereNumber('id')
    ->middleware(['auth.session', 'capability:lifecycle.checklist.manage'])
    ->name('lifecycle.onboarding-tasks.update');

/*
|--------------------------------------------------------------------------
| Talent thin slices: recruitment, performance, training
|--------------------------------------------------------------------------
|
| Commands are capability-guarded and require Idempotency-Key. Offers and
| appeals create Workflow Cases; no approval status is trusted from clients.
|
*/
Route::prefix('recruitment')->middleware(['auth.session'])->group(function (): void {
    Route::get('/requisitions', [RecruitmentController::class, 'requisitions'])
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.requisitions.index');
    Route::post('/requisitions', [RecruitmentController::class, 'storeRequisition'])
        ->middleware('capability:recruitment.requisition.manage,LEGAL_ENTITY,legal_entity_id')
        ->name('recruitment.requisitions.store');
    Route::get('/candidates', [RecruitmentController::class, 'candidates'])
        ->middleware('capability:recruitment.candidate.read')
        ->name('recruitment.candidates.index');
    Route::get('/requisitions/{id}/openings', [App\Modules\Talent\Http\Controllers\RecruitmentOpeningController::class, 'index'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.openings.index');
    Route::post('/requisitions/{id}/openings', [App\Modules\Talent\Http\Controllers\RecruitmentOpeningController::class, 'store'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.requisition.manage')
        ->name('recruitment.openings.store');
    Route::post('/candidates', [RecruitmentController::class, 'storeCandidate'])
        ->middleware('capability:recruitment.candidate.manage')
        ->name('recruitment.candidates.store');
    Route::post('/candidates/{id}/interviews', [RecruitmentController::class, 'storeInterview'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.interview.manage')
        ->name('recruitment.interviews.store');
    Route::get('/offers', [RecruitmentController::class, 'offers'])->middleware('capability:recruitment.offer.manage')->name('recruitment.offers.index');
    Route::post('/offers', [RecruitmentController::class, 'storeOffer'])
        ->middleware('capability:recruitment.offer.manage')
        ->name('recruitment.offers.store');
    Route::post('/offers/{id}/accept', [RecruitmentController::class, 'acceptOffer'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.offer.accept')
        ->name('recruitment.offers.accept');
});

Route::prefix('performance')->middleware(['auth.session'])->group(function (): void {
    Route::get('/templates', [PerformanceTemplateController::class, 'index'])
        ->middleware('capability:performance.cycle.read')
        ->name('performance.templates.index');
    Route::post('/templates', [PerformanceTemplateController::class, 'store'])
        ->middleware('capability:performance.cycle.manage')
        ->name('performance.templates.store');

    Route::get('/cycles', [PerformanceController::class, 'cycles'])
        ->middleware('capability:performance.cycle.read')
        ->name('performance.cycles.index');
    Route::post('/cycles', [PerformanceController::class, 'storeCycle'])
        ->middleware('capability:performance.cycle.manage')
        ->name('performance.cycles.store');
    Route::post('/reviews', [PerformanceController::class, 'storeReview'])
        ->middleware('capability:performance.review.manage')
        ->name('performance.reviews.store');
    Route::post('/reviews/{id}/submit', [PerformanceController::class, 'submitReview'])
        ->whereNumber('id')
        ->middleware('capability:performance.review.submit')
        ->name('performance.reviews.submit');
    Route::post('/reviews/{id}/appeals', [PerformanceController::class, 'storeAppeal'])
        ->whereNumber('id')
        ->middleware('capability:performance.appeal.manage')
        ->name('performance.reviews.appeals.store');
});

Route::prefix('training')->middleware(['auth.session'])->group(function (): void {
    Route::get('/programs', [TrainingController::class, 'programs'])
        ->middleware('capability:training.program.read')
        ->name('training.programs.index');
    Route::post('/programs', [TrainingController::class, 'storeProgram'])
        ->middleware('capability:training.program.manage')
        ->name('training.programs.store');
    Route::post('/sessions', [TrainingController::class, 'storeSession'])
        ->middleware('capability:training.session.manage')
        ->name('training.sessions.store');
    Route::post('/enrollments', [TrainingController::class, 'storeEnrollment'])
        ->middleware('capability:training.enrollment.manage')
        ->name('training.enrollments.store');
    Route::post('/enrollments/{id}/complete', [TrainingController::class, 'completeEnrollment'])
        ->whereNumber('id')
        ->middleware('capability:training.completion.manage')
        ->name('training.enrollments.complete');
    Route::get('/report', [TrainingController::class, 'report'])
        ->middleware('capability:training.report.read')
        ->name('training.report.index');
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
    Route::get('/runs/{id}/inputs', [PayrollRunController::class, 'inputs'])
        ->whereNumber('id')
        ->middleware('capability:payroll.run.read,PAYROLL_RUN')
        ->name('payroll.runs.inputs');
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
