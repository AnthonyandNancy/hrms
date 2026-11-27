<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\NoticeController;
use App\Modules\Engagement\Http\Controllers\NoticeGovernanceController;
use App\Modules\Engagement\Http\Controllers\NotificationController;
use App\Modules\IAM\Http\Controllers\AccessReviewController;
use App\Modules\IAM\Http\Controllers\AuditQueryController;
use App\Modules\IAM\Http\Controllers\AuthController;
use App\Modules\IAM\Http\Controllers\AuthorizationAdminController;
use App\Modules\IAM\Http\Controllers\BreakGlassController;
use App\Modules\IAM\Http\Controllers\DataScopeAdminController;
use App\Modules\IAM\Http\Controllers\FieldPolicyAdminController;
use App\Modules\IAM\Http\Controllers\MeController;
use App\Modules\IAM\Http\Controllers\MenuAdminController;
use App\Modules\IAM\Http\Controllers\MfaController;
use App\Modules\IAM\Http\Controllers\PermissionPreviewController;
use App\Modules\IAM\Http\Controllers\RoleAdminController;
use App\Modules\IAM\Http\Controllers\RoleAuthorizationController;
use App\Modules\IAM\Http\Controllers\RolePageController;
use App\Modules\IAM\Http\Controllers\UserAdminController;
use App\Modules\IAM\Http\Controllers\WorkspaceController;
use App\Modules\Lifecycle\Http\Controllers\LifecycleController;
use App\Modules\Lifecycle\Http\Controllers\SecondmentController;
use App\Modules\Organization\Http\Controllers\CompanyProfileController;
use App\Modules\Organization\Http\Controllers\DepartmentController;
use App\Modules\Organization\Http\Controllers\OrgUnitController;
use App\Modules\Organization\Http\Controllers\PositionCatalogController;
use App\Modules\Organization\Http\Controllers\PositionController;
use App\Modules\Payroll\Http\Controllers\BenefitController;
use App\Modules\Payroll\Http\Controllers\CompensationController;
use App\Modules\Payroll\Http\Controllers\PayrollCatalogController;
use App\Modules\Payroll\Http\Controllers\PayrollPayslipController;
use App\Modules\Payroll\Http\Controllers\PayrollReportController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayrollStatutoryParameterController;
use App\Modules\People\Http\Controllers\ContractLifecycleController;
use App\Modules\People\Http\Controllers\EmployeeController;
use App\Modules\People\Http\Controllers\EmployeeDocumentController;
use App\Modules\People\Http\Controllers\EmployeeProfileController;
use App\Modules\People\Http\Controllers\EmploymentContractController;
use App\Modules\People\Http\Controllers\EmploymentController;
use App\Modules\People\Http\Controllers\PersonController;
use App\Modules\Platform\Http\Controllers\DictionaryAdminController;
use App\Modules\Platform\Http\Controllers\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\LivenessController;
use App\Modules\Platform\Http\Controllers\MetaController;
use App\Modules\Platform\Http\Controllers\NotificationAdminController;
use App\Modules\Platform\Http\Controllers\ReadinessController;
use App\Modules\Platform\Http\Middleware\IdempotentCommand;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\ReportingController;
use App\Modules\Reporting\Http\Controllers\ReportSubscriptionController;
use App\Modules\Talent\Http\Controllers\PerformanceController;
use App\Modules\Talent\Http\Controllers\PerformanceCycleController;
use App\Modules\Talent\Http\Controllers\PerformanceTemplateController;
use App\Modules\Talent\Http\Controllers\RecruitmentController;
use App\Modules\Talent\Http\Controllers\RecruitmentFunnelController;
use App\Modules\Talent\Http\Controllers\TrainingController;
use App\Modules\Time\Http\Controllers\AttendanceClosingController;
use App\Modules\Time\Http\Controllers\AttendanceController;
use App\Modules\Time\Http\Controllers\AttendanceOpsController;
use App\Modules\Time\Http\Controllers\AttendanceRecordController;
use App\Modules\Time\Http\Controllers\CalendarAdminController;
use App\Modules\Time\Http\Controllers\LeaveAdminController;
use App\Modules\Time\Http\Controllers\LeaveController;
use App\Modules\Time\Http\Controllers\LeaveOnBehalfController;
use App\Modules\Time\Http\Controllers\LeavePolicyController;
use App\Modules\Time\Http\Controllers\LeaveReportController;
use App\Modules\Time\Http\Controllers\LeaveTypeController;
use App\Modules\Time\Http\Controllers\OvertimeController;
use App\Modules\Time\Http\Controllers\OvertimeRequestController;
use App\Modules\Time\Http\Controllers\ShiftAdminController;
use App\Modules\Time\Http\Controllers\TimeCatalogController;
use App\Modules\Time\Http\Controllers\TravelController;
use App\Modules\Time\Http\Controllers\TravelExpenseController;
use App\Modules\Time\Http\Controllers\TravelPolicyController;
use App\Modules\Time\Http\Controllers\TravelReportController;
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
Route::post('/auth/mfa/challenge', [MfaController::class, 'completeChallenge'])->middleware('throttle:5,1')->name('auth.mfa.challenge');

Route::middleware(['auth.session', 'csrf.header'])->group(function (): void {
    Route::get('/me/workspace', [WorkspaceController::class, 'show'])->middleware('capability:iam.session.manage,SELF')->name('me.workspace');
    Route::get('/me', [MeController::class, 'show'])->middleware('capability:iam.session.manage,SELF')->name('me.show');
    Route::get('/me/capabilities', [MeController::class, 'capabilities'])->middleware('capability:iam.session.manage,SELF')->name('me.capabilities');
    Route::get('/me/permissions', [MeController::class, 'permissions'])->middleware('capability:iam.session.manage,SELF')->name('me.permissions');

    Route::get('/auth/csrf', [AuthController::class, 'csrf'])->middleware('capability:iam.session.manage,SELF')->name('auth.csrf');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('capability:iam.session.manage,SELF')->name('auth.logout');
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->middleware('capability:iam.session.manage,SELF')->name('auth.logout-all');
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('capability:iam.session.manage,SELF')->name('auth.password');
    Route::get('/auth/sessions', [AuthController::class, 'sessions'])->middleware('capability:iam.session.manage,SELF')->name('auth.sessions.index');
    // Session ids are ULIDs, not integers.
    Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession'])->where('id', '[0-9A-Za-z]{26}')->middleware('capability:iam.session.manage,SELF')->name('auth.sessions.revoke');
    // The deployment's MFA posture is served by the API so the UI never guesses.
    Route::get('/auth/mfa/status', [MfaController::class, 'status'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.status');
    Route::post('/auth/mfa/totp/disable', [MfaController::class, 'disableTotp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.totp.disable');
    Route::post('/auth/mfa/totp/enroll', [MfaController::class, 'enrollTotp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.totp.enroll');
    Route::post('/auth/mfa/totp/confirm', [MfaController::class, 'confirmTotp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.totp.confirm');
    Route::post('/auth/mfa/step-up', [MfaController::class, 'stepUp'])->middleware('capability:iam.session.manage,SELF')->name('auth.mfa.step-up');
    Route::post('/auth/webauthn/register/options', [MfaController::class, 'webauthnRegisterOptions'])->middleware('capability:iam.session.manage,SELF')->name('auth.webauthn.register.options');
    Route::post('/auth/webauthn/assert/options', [MfaController::class, 'webauthnAssertOptions'])->middleware('capability:iam.session.manage,SELF')->name('auth.webauthn.assert.options');

    Route::get('/users', [UserAdminController::class, 'index'])->middleware(['mfa.enrolled', 'capability:iam.user.read,COMPANY'])->name('users.index');
    Route::post('/users', [UserAdminController::class, 'store'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.user.manage,COMPANY'])->name('users.store');
    Route::patch('/users/{id}', [UserAdminController::class, 'update'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.user.manage,COMPANY'])->name('users.update');
    Route::post('/users/{id}/roles', [UserAdminController::class, 'assignRole'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('users.roles.store');
    Route::delete('/users/{id}/roles/{roleCode}', [UserAdminController::class, 'revokeRole'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('users.roles.destroy');
    Route::get('/users/{id}/permissions-preview', PermissionPreviewController::class)->whereNumber('id')->middleware(['mfa.enrolled', 'capability:iam.user.read,COMPANY'])->name('users.permissions-preview');

    Route::get('/roles', [AuthorizationAdminController::class, 'roles'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,COMPANY'])->name('roles.index');
    Route::post('/roles', [RoleAdminController::class, 'store'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.store');
    Route::patch('/roles/{id}', [RoleAdminController::class, 'update'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.update');
    Route::delete('/roles/{id}', [RoleAdminController::class, 'destroy'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.destroy');
    Route::post('/roles/{id}/copy', [RoleAdminController::class, 'copy'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.copy');
    Route::put('/roles/{id}/authorization', [RoleAuthorizationController::class, 'update'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.authorization.replace');
    Route::put('/roles/{id}/capabilities', [RoleAdminController::class, 'capabilities'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.capabilities.replace');
    Route::get('/roles/{id}/pages', [RolePageController::class, 'index'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,COMPANY'])->name('roles.pages.index');
    Route::get('/pages/catalog', [RolePageController::class, 'catalog'])->middleware(['mfa.enrolled', 'capability:iam.role.manage,COMPANY'])->name('pages.catalog');
    Route::put('/roles/{id}/pages', [RolePageController::class, 'update'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.role.manage,COMPANY'])->name('roles.pages.replace');
    Route::get('/capabilities', [AuthorizationAdminController::class, 'capabilities'])->middleware(['mfa.enrolled', 'capability:iam.permission.read,COMPANY'])->name('capabilities.index');

    Route::get('/system/menus', [MenuAdminController::class, 'index'])->middleware(['mfa.enrolled', 'capability:iam.menu.manage,COMPANY'])->name('system.menus.index');
    Route::post('/system/menus', [MenuAdminController::class, 'store'])->middleware(['mfa.enrolled', 'capability:iam.menu.manage,COMPANY'])->name('system.menus.store');
    Route::patch('/system/menus/{page_code}', [MenuAdminController::class, 'update'])->middleware(['mfa.enrolled', 'capability:iam.menu.manage,COMPANY'])->name('system.menus.update');
    Route::delete('/system/menus/{page_code}', [MenuAdminController::class, 'destroy'])->middleware(['mfa.enrolled', 'capability:iam.menu.manage,COMPANY'])->name('system.menus.destroy');

    Route::get('/system/data-scopes', [DataScopeAdminController::class, 'index'])->middleware(['mfa.enrolled', 'capability:iam.scope.manage,COMPANY'])->name('system.data-scopes.index');
    Route::put('/system/data-scopes/{id}', [DataScopeAdminController::class, 'update'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.scope.manage,COMPANY'])->name('system.data-scopes.update');

    Route::get('/system/field-policies', [FieldPolicyAdminController::class, 'index'])->middleware(['mfa.enrolled', 'capability:iam.field.manage,COMPANY'])->name('system.field-policies.index');
    Route::put('/system/field-policies/{field_key}', [FieldPolicyAdminController::class, 'update'])->where('field_key', '.*')->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.field.manage,COMPANY'])->name('system.field-policies.update');

    Route::get('/system/dictionaries', [DictionaryAdminController::class, 'dictionaries'])->middleware(['mfa.enrolled', 'capability:system.dictionary.manage,COMPANY'])->name('system.dictionaries.index');
    Route::post('/system/dictionaries', [DictionaryAdminController::class, 'storeDictionary'])->middleware(['mfa.enrolled', 'capability:system.dictionary.manage,COMPANY'])->name('system.dictionaries.store');
    Route::patch('/system/dictionaries/{id}', [DictionaryAdminController::class, 'updateDictionary'])->whereNumber('id')->middleware(['mfa.enrolled', 'capability:system.dictionary.manage,COMPANY'])->name('system.dictionaries.update');

    Route::get('/system/parameters', [DictionaryAdminController::class, 'parameters'])->middleware(['mfa.enrolled', 'capability:system.parameter.manage,COMPANY'])->name('system.parameters.index');
    Route::put('/system/parameters', [DictionaryAdminController::class, 'updateParameter'])->middleware(['mfa.enrolled', 'capability:system.parameter.manage,COMPANY'])->name('system.parameters.update');

    Route::post('/system/calendars/publish', [CalendarAdminController::class, 'publish'])
        ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:time.calendar.manage,COMPANY'])
        ->name('system.calendars.publish');

    Route::get('/users/{id}/bindings', [AuthorizationAdminController::class, 'bindings'])->middleware(['mfa.enrolled', 'capability:iam.binding.manage,COMPANY'])->name('bindings.index');
    Route::post('/users/{id}/bindings', [AuthorizationAdminController::class, 'storeBinding'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.binding.manage,COMPANY'])->name('bindings.store');
    Route::delete('/bindings/{id}', [AuthorizationAdminController::class, 'destroyBinding'])->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:iam.binding.manage,COMPANY'])->name('bindings.destroy');

    Route::get('/system/company-profile', [CompanyProfileController::class, 'show'])
        ->middleware('capability:system.settings.manage,COMPANY')
        ->name('system.company-profile.show');
    Route::patch('/system/company-profile', [CompanyProfileController::class, 'save'])
        ->middleware('capability:system.settings.manage,COMPANY')
        ->name('system.company-profile.save');
    Route::get('/audit/events', [AuditQueryController::class, 'index'])
        ->middleware('capability:iam.audit.read,COMPANY')
        ->name('audit.events.index');
    Route::post('/audit/events/export', [AuditQueryController::class, 'export'])
        ->middleware(['mfa.enrolled', 'capability:iam.audit.read,COMPANY'])
        ->name('audit.events.export');
    Route::post('/auth/break-glass', [BreakGlassController::class, 'activate'])->middleware(['mfa.step-up', 'capability:iam.session.manage,SELF'])->name('auth.break-glass.activate');
    Route::post('/auth/break-glass/{id}/revoke', [BreakGlassController::class, 'revoke'])->middleware('capability:iam.session.manage,SELF')->name('auth.break-glass.revoke');

    Route::get('/access-review', [AccessReviewController::class, 'show'])->middleware(['mfa.enrolled', 'capability:iam.audit.read,COMPANY'])->name('access-review.show');

    Route::get('/auth/events', [AuthorizationAdminController::class, 'authEvents'])->middleware(['mfa.enrolled', 'capability:iam.audit.read,COMPANY'])->name('auth.events');
});

Route::prefix('employees')->middleware('auth.session')->group(function (): void {
    Route::post('/onboard', [EmployeeController::class, 'onboard'])
        ->middleware(['csrf.header', 'mfa.step-up', 'capability:people.employee.manage,COMPANY'])
        ->name('employees.onboard');
    Route::get('/', [EmployeeController::class, 'index'])
        ->middleware('capability:people.employee.read,COMPANY')
        ->name('employees.index');
    Route::get('/export', [EmployeeController::class, 'export'])
        ->middleware('capability:people.employee.export,COMPANY')
        ->name('employees.export');
    Route::get('/{employment_id}', [EmployeeController::class, 'show'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.employee.read,COMPANY')
        ->name('employees.show');
    Route::delete('/{employment_id}', [EmployeeController::class, 'archive'])
        ->whereNumber('employment_id')
        ->middleware(['mfa.step-up', 'capability:people.employee.archive,COMPANY'])
        ->name('employees.archive');
    Route::post('/{employment_id}/restore', [EmployeeController::class, 'restore'])
        ->whereNumber('employment_id')
        ->middleware(['mfa.step-up', 'capability:people.employee.restore,COMPANY'])
        ->name('employees.restore');
});

Route::get('/employee-imports', [EmployeeController::class, 'imports'])
    ->middleware(['auth.session', 'capability:people.employee.manage,COMPANY'])
    ->name('employee-imports.index');

Route::prefix('employees/{employmentId}')->middleware('auth.session')->group(function (): void {
    foreach (['education', 'experiences', 'contacts', 'certificates', 'change_requests'] as $profileResource) {
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

    // M04 append-only retire for versioned sub-records. The four concrete
    // resources are enumerated so the generic placeholder never becomes a
    // client-visible route template.
    foreach (['education', 'experiences', 'contacts', 'certificates'] as $profileResource) {
        Route::delete('/'.$profileResource.'/{id}', [EmployeeProfileController::class, 'retire'])
            ->whereNumber('employmentId')
            ->whereNumber('id')
            ->defaults('profileResource', $profileResource)
            ->middleware([IdempotentCommand::class, 'capability:people.employee.manage'])
            ->name('employees.profile.'.$profileResource.'.retire');
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

Route::get('/employments/contracts', [ContractLifecycleController::class, 'contracts'])
    ->middleware(['auth.session', 'capability:people.contract.read'])
    ->name('employments.contracts.ledger');

Route::prefix('employment-contracts/{id}')->middleware('auth.session')->group(function (): void {
    Route::delete('/', [ContractLifecycleController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('capability:people.contract.delete')
        ->name('employment-contracts.destroy');
    Route::post('/restore', [ContractLifecycleController::class, 'restore'])
        ->whereNumber('id')
        ->middleware('capability:people.contract.restore')
        ->name('employment-contracts.restore');
    Route::get('/amendments', [ContractLifecycleController::class, 'amendments'])
        ->whereNumber('id')
        ->middleware('capability:people.contract.read')
        ->name('employment-contracts.amendments.index');
    Route::post('/amendments', [ContractLifecycleController::class, 'amend'])
        ->whereNumber('id')
        ->middleware('capability:people.contract.amend')
        ->name('employment-contracts.amendments.store');
    Route::post('/terminate', [ContractLifecycleController::class, 'terminate'])
        ->whereNumber('id')
        ->middleware('capability:people.contract.terminate')
        ->name('employment-contracts.terminate');
});

Route::prefix('contract-templates')->middleware('auth.session')->group(function (): void {
    Route::get('/', [ContractLifecycleController::class, 'templates'])
        ->middleware('capability:people.contract.read')
        ->name('contract-templates.index');
    Route::post('/', [ContractLifecycleController::class, 'createTemplate'])
        ->middleware('capability:people.contract.template.manage')
        ->name('contract-templates.store');
    Route::post('/{id}/publish', [ContractLifecycleController::class, 'publishTemplate'])
        ->whereNumber('id')
        ->middleware('capability:people.contract.template.manage')
        ->name('contract-templates.publish');
});

Route::prefix('employments/{employmentId}/contracts')->middleware('auth.session')->group(function (): void {
    Route::get('/', [EmploymentContractController::class, 'index'])
        ->whereNumber('employmentId')
        ->middleware('capability:people.contract.read')
        ->name('employments.contracts.index');
    Route::post('/', [EmploymentContractController::class, 'store'])
        ->whereNumber('employmentId')
        ->middleware(['csrf.header', 'mfa.enrolled', 'capability:people.contract.manage'])
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

// K3 binary document surface. It deliberately lives under a distinct prefix:
// `POST /v1/employments/{id}/documents` already means "register an existing
// file_object_id", and taking that path over would silently change an existing
// contract that other pages depend on.
Route::prefix('employments/{employment_id}/document-files')->middleware('auth.session')->group(function (): void {
    Route::get('/', [EmployeeDocumentController::class, 'index'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.document.read')
        ->name('employments.document-files.index');
    Route::post('/', [EmployeeDocumentController::class, 'store'])
        ->whereNumber('employment_id')
        ->middleware('capability:people.document.read')
        ->name('employments.document-files.upload');
});

Route::get('/documents/{document_id}/signed-download', [EmployeeDocumentController::class, 'signedDownload'])
    ->whereNumber('document_id')
    ->middleware('auth.session')
    ->middleware('capability:people.document.download')
    ->name('documents.signed-download');

// Signed-URL content delivery: the signature proves WHICH document, and the
// capability proves WHO may ever receive bytes. Both are required, so this
// route stays inside the universal default-deny rule.
Route::get('/documents/{document_id}/content', [EmployeeDocumentController::class, 'content'])
    ->whereNumber('document_id')
    ->middleware(['auth.session', 'signed', 'capability:people.document.download'])
    ->name('documents.content');

Route::delete('/documents/{document_id}', [EmployeeDocumentController::class, 'destroy'])
    ->whereNumber('document_id')
    ->middleware('auth.session')
    ->middleware('capability:people.employee.manage')
    ->name('documents.destroy');

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
    Route::post('/', [DepartmentController::class, 'store'])
        ->middleware(['csrf.header', 'capability:org.unit.manage,COMPANY'])
        ->name('departments.store');
    Route::patch('/{id}', [DepartmentController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:org.unit.manage,COMPANY'])
        ->name('departments.update');
    Route::delete('/{id}/members/{employmentId}', [DepartmentController::class, 'removeMember'])
        ->whereNumber('id')
        ->whereNumber('employmentId')
        ->middleware(['csrf.header', 'capability:org.unit.manage,COMPANY'])
        ->name('departments.members.destroy');
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
    Route::get('/grades', [PositionCatalogController::class, 'grades'])
        ->middleware('capability:org.position.grade.manage,COMPANY')
        ->name('positions.grades.index');
    Route::post('/grades', [PositionCatalogController::class, 'storeGrade'])
        ->middleware(['mfa.enrolled', 'capability:org.position.grade.manage,COMPANY'])
        ->name('positions.grades.store');
    Route::get('/{id}/requirements', [PositionCatalogController::class, 'requirements'])
        ->whereNumber('id')
        ->middleware('capability:org.position.qualification.manage,COMPANY')
        ->name('positions.requirements.index');
    Route::post('/{id}/requirements', [PositionCatalogController::class, 'storeRequirements'])
        ->whereNumber('id')
        ->middleware(['mfa.enrolled', 'capability:org.position.qualification.manage,COMPANY'])
        ->name('positions.requirements.store');
    Route::get('/{id}/staff', [PositionCatalogController::class, 'staff'])
        ->whereNumber('id')
        ->middleware('capability:org.position.staff.read,COMPANY')
        ->name('positions.staff.index');
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

    // M09 literal reads must precede the numeric {employment_id} route above;
    // they are registered here explicitly to keep that ordering visible.
    Route::get('/attendance/records', [AttendanceRecordController::class, 'index'])
        ->middleware('capability:time.attendance.read')
        ->name('time.attendance.records.index');
    Route::get('/attendance/records/export', [AttendanceRecordController::class, 'export'])
        ->middleware('capability:time.attendance.export')
        ->name('time.attendance.records.export');
    Route::get('/punches', [AttendanceRecordController::class, 'punches'])
        ->middleware('capability:time.punch.read')
        ->name('time.punches.index');

    Route::post('/shifts', [ShiftAdminController::class, 'store'])
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.shift.manage,COMPANY'])
        ->name('time.shifts.store');
    Route::patch('/shifts/{id}', [ShiftAdminController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.shift.manage,COMPANY'])
        ->name('time.shifts.update');
    Route::post('/shifts/{id}/disable', [ShiftAdminController::class, 'disable'])
        ->whereNumber('id')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.shift.manage,COMPANY'])
        ->name('time.shifts.disable');

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

    Route::get('/calendars', [TimeCatalogController::class, 'calendar'])
        ->middleware('capability:time.calendar.read')
        ->name('time.calendars.show');

    Route::get('/shifts', [TimeCatalogController::class, 'shifts'])
        ->middleware('capability:time.shift.read')
        ->name('time.shifts.index');

    // Attendance rule versions (effective-dated, append-only) and the per-day
    // shift schedule surface. Reads use the projection envelope; the publish
    // and schedule writes go through CommandRunner with Idempotency-Key.
    Route::get('/attendance/rules', [AttendanceOpsController::class, 'rules'])
        ->middleware('capability:time.attendance.rule.read')
        ->name('time.attendance.rules.index');
    Route::post('/attendance/rules', [AttendanceOpsController::class, 'publishRule'])
        ->middleware(['csrf.header', 'capability:time.attendance.rule.manage'])
        ->name('time.attendance.rules.store');

    Route::get('/shift-schedules', [AttendanceOpsController::class, 'schedules'])
        ->middleware('capability:time.shift.schedule.read')
        ->name('time.shift-schedules.index');
    Route::post('/shift-schedules', [AttendanceOpsController::class, 'storeSchedule'])
        ->middleware(['csrf.header', 'capability:time.shift.schedule.manage'])
        ->name('time.shift-schedules.store');

    Route::get('/attendance/closings', [AttendanceOpsController::class, 'closings'])
        ->middleware('capability:time.attendance.read')
        ->name('time.attendance.closings.index');
    Route::get('/attendance/devices', [AttendanceOpsController::class, 'devices'])
        ->middleware('capability:time.attendance.device.read')
        ->name('time.attendance.devices.index');

    Route::get('/leave/types', [TimeCatalogController::class, 'leaveTypes'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.types.index');
    Route::post('/leave/types', [LeaveTypeController::class, 'store'])
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.leave.type.manage,COMPANY'])
        ->name('time.leave.types.store');
    Route::patch('/leave/types/{id}', [LeaveTypeController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.leave.type.manage,COMPANY'])
        ->name('time.leave.types.update');
    Route::post('/leave/policies', [LeavePolicyController::class, 'store'])
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.leave.policy.manage,COMPANY'])
        ->name('time.leave.policies.store');
    Route::post('/leave/policies/{id}/retire', [LeavePolicyController::class, 'retire'])
        ->whereNumber('id')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.leave.policy.manage,COMPANY'])
        ->name('time.leave.policies.retire');
    Route::post('/leave/requests/on-behalf', [LeaveOnBehalfController::class, 'store'])
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.leave.request.on-behalf,MANAGED'])
        ->name('time.leave.requests.on-behalf');
    Route::post('/leave/requests/{caseId}/revoke', [LeaveAdminController::class, 'revoke'])
        ->whereNumber('caseId')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.leave.admin'])
        ->name('time.leave.requests.revoke');
    Route::get('/leave/reports/summary', [LeaveReportController::class, 'summary'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.reports.summary');
    Route::get('/leave/policies', [TimeCatalogController::class, 'leavePolicies'])
        ->middleware('capability:time.leave.read')
        ->name('time.leave.policies.index');
    Route::get('/overtime/records', [TimeCatalogController::class, 'overtimeRecords'])
        ->middleware('capability:time.overtime.read')
        ->name('time.overtime.records.index');
    Route::get('/overtime/policies', [TimeCatalogController::class, 'overtimePolicies'])
        ->middleware('capability:time.overtime.read')
        ->name('time.overtime.policies.show');
    Route::post('/overtime/policies', [TimeCatalogController::class, 'storeOvertimePolicy'])
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.overtime.manage,COMPANY'])
        ->name('time.overtime.policies.store');
    Route::post('/overtime/policies/{id}/retire', [TimeCatalogController::class, 'retireOvertimePolicy'])
        ->whereNumber('id')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.overtime.manage,COMPANY'])
        ->name('time.overtime.policies.retire');
    Route::get('/overtime/settlements', [OvertimeController::class, 'settlements'])
        ->middleware('capability:time.overtime.read')
        ->name('time.overtime.settlements.index');
    Route::get('/overtime/requests', [OvertimeRequestController::class, 'index'])
        ->middleware('capability:time.overtime.read')
        ->name('time.overtime.requests.index');
    Route::post('/overtime/requests', [OvertimeRequestController::class, 'store'])
        ->middleware('capability:time.overtime.request')
        ->name('time.overtime.requests.store');
    Route::post('/overtime/requests/{id}/confirm', [OvertimeRequestController::class, 'confirm'])
        ->whereNumber('id')
        ->middleware('capability:time.overtime.confirm')
        ->name('time.overtime.requests.confirm');
    Route::get('/overtime/timeoff-balances', [OvertimeRequestController::class, 'timeoffBalances'])
        ->middleware('capability:time.timeoff.read')
        ->name('time.overtime.timeoff-balances');
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

Route::prefix('travel')->middleware(['auth.session', 'csrf.header'])->group(function (): void {
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
    Route::post('/requests/{id}/approve', [TravelController::class, 'approve'])
        ->whereNumber('id')
        ->middleware(['mfa.enrolled', 'capability:time.travel.approve,MANAGED'])
        ->name('time.travel.requests.approve');
    Route::post('/requests/{id}/change', [TravelController::class, 'change'])
        ->whereNumber('id')
        ->middleware(['csrf.header', IdempotentCommand::class, 'capability:time.travel.request'])
        ->name('time.travel.requests.change');
    Route::get('/reports/summary', [TravelReportController::class, 'summary'])
        ->middleware('capability:time.travel.read')
        ->name('time.travel.reports.summary');

    Route::get('/policies', [TravelPolicyController::class, 'index'])
        ->middleware('capability:time.travel.policy.read,COMPANY')
        ->name('time.travel.policies.index');
    Route::post('/policies', [TravelPolicyController::class, 'store'])
        ->middleware(['mfa.enrolled', 'capability:time.travel.policy.manage,COMPANY'])
        ->name('time.travel.policies.store');

    Route::get('/expenses', [TravelExpenseController::class, 'index'])
        ->middleware('capability:time.travel.expense')
        ->name('time.travel.expenses.index');
    Route::post('/expenses', [TravelExpenseController::class, 'store'])
        ->middleware(['mfa.enrolled', 'capability:time.travel.expense.manage,COMPANY'])
        ->name('time.travel.expenses.store');
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
    Route::post('/notices/audience-preview', [NoticeController::class, 'previewAudience'])
        ->middleware('capability:engagement.notice.manage,COMPANY')
        ->name('engagement.notices.audience-preview');
    Route::get('/notices', [NoticeController::class, 'index'])
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.index');
    Route::get('/notices/{notice}', [NoticeController::class, 'show'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.show');
    Route::post('/notices', [NoticeController::class, 'store'])
        ->middleware('capability:engagement.notice.manage,COMPANY')
        ->name('engagement.notices.store');
    Route::post('/notices/{notice}/acknowledge', [NoticeController::class, 'acknowledge'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.acknowledge');
    Route::post('/notices/{notice}/retract', [NoticeController::class, 'retract'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.manage,COMPANY')
        ->name('engagement.notices.retract');

    // Notice approval is a Workflow Case decision, never a status write.
    Route::post('/notices/{notice}/submit', [NoticeGovernanceController::class, 'submit'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.manage')
        ->name('engagement.notices.submit');
    Route::post('/notices/{notice}/decision', [NoticeGovernanceController::class, 'decision'])
        ->whereNumber('notice')
        ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:engagement.notice.publish'])
        ->name('engagement.notices.decision');
    Route::post('/notices/{notice}/publish', [NoticeGovernanceController::class, 'publish'])
        ->whereNumber('notice')
        ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:engagement.notice.publish'])
        ->name('engagement.notices.publish');
    Route::get('/notices/{notice}/audience', [NoticeGovernanceController::class, 'audience'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.manage')
        ->name('engagement.notices.audience');
    Route::get('/notices/{notice}/reads', [NoticeGovernanceController::class, 'reads'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notices.reads');
    Route::get('/notices/{notice}/reads/export', [NoticeGovernanceController::class, 'exportReads'])
        ->whereNumber('notice')
        ->middleware(['mfa.enrolled', 'capability:engagement.notice.export'])
        ->name('engagement.notices.reads.export');

    Route::get('/notice-templates', [NoticeGovernanceController::class, 'templates'])
        ->middleware('capability:engagement.notice.read')
        ->name('engagement.notice-templates.index');
    Route::post('/notices/{notice}/remind', [NoticeGovernanceController::class, 'remind'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.manage,COMPANY')
        ->name('engagement.notices.remind');
    Route::post('/notice-templates', [NoticeGovernanceController::class, 'storeTemplate'])
        ->middleware('capability:engagement.notice.manage')
        ->name('engagement.notice-templates.store');

    // Static archive path must be registered before /notices/{notice}.
    Route::get('/notices/archive', [NoticeGovernanceController::class, 'archiveIndex'])
        ->middleware('capability:engagement.notice.manage')
        ->name('engagement.notices.archive.index');
    Route::post('/notices/{notice}/archive', [NoticeGovernanceController::class, 'archive'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.manage')
        ->name('engagement.notices.archive');
    Route::post('/notices/{notice}/restore', [NoticeGovernanceController::class, 'restore'])
        ->whereNumber('notice')
        ->middleware('capability:engagement.notice.manage')
        ->name('engagement.notices.restore');

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('capability:notification.read')
        ->name('engagement.notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification')
        ->middleware('capability:notification.read')
        ->name('engagement.notifications.read');
});

Route::prefix('notification-templates')->middleware(['auth.session'])->group(function (): void {
    Route::get('/', [NotificationAdminController::class, 'templates'])
        ->middleware('capability:notification.template.manage,COMPANY')
        ->name('notification-templates.index');
    Route::post('/', [NotificationAdminController::class, 'storeTemplate'])
        ->middleware('capability:notification.template.manage,COMPANY')
        ->name('notification-templates.store');
});

Route::prefix('notifications')->middleware(['auth.session'])->group(function (): void {
    Route::get('/deliveries', [NotificationAdminController::class, 'deliveries'])
        ->middleware('capability:notification.delivery.manage,COMPANY')
        ->name('notifications.deliveries.index');
    Route::post('/dispatch', [NotificationAdminController::class, 'dispatch'])
        ->middleware('capability:notification.delivery.manage,COMPANY')
        ->name('notifications.dispatch');
    Route::post('/deliveries/{id}/retry', [NotificationAdminController::class, 'retry'])
        ->whereNumber('id')
        ->middleware('capability:notification.delivery.manage,COMPANY')
        ->name('notifications.deliveries.retry');
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
        ->middleware('capability:lifecycle.onboarding.manage,COMPANY')
        ->name('lifecycle.onboarding.store');
    Route::post('/probation-confirmations', [LifecycleController::class, 'probationConfirmation'])
        ->middleware('capability:lifecycle.probation.manage')
        ->name('lifecycle.probation.store');
    Route::post('/transfers', [LifecycleController::class, 'transfer'])
        ->middleware('capability:lifecycle.transfer.manage,COMPANY')
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
        ->middleware('capability:lifecycle.rehire.manage,COMPANY')
        ->name('lifecycle.rehires.store');
    Route::get('/secondments', [SecondmentController::class, 'index'])
        ->middleware(['mfa.enrolled', 'capability:lifecycle.secondment.manage,COMPANY'])
        ->name('lifecycle.secondments.index');
    Route::post('/secondments', [SecondmentController::class, 'store'])
        ->middleware(['mfa.enrolled', 'capability:lifecycle.secondment.manage,COMPANY'])
        ->name('lifecycle.secondments.store');
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
    Route::get('/board', [RecruitmentFunnelController::class, 'board'])
        ->middleware('capability:recruitment.board.read')
        ->name('recruitment.board');
    Route::get('/budget', [RecruitmentFunnelController::class, 'budget'])
        ->middleware('capability:recruitment.budget.read')
        ->name('recruitment.budget');
    Route::get('/reports', [RecruitmentFunnelController::class, 'reports'])
        ->middleware('capability:recruitment.board.read')
        ->name('recruitment.reports');
    Route::post('/candidates/imports', [RecruitmentFunnelController::class, 'importCandidates'])
        ->middleware(['csrf.header', 'mfa.enrolled', 'capability:recruitment.candidate.import'])
        ->name('recruitment.candidates.imports.store');
    Route::get('/candidates', [RecruitmentFunnelController::class, 'candidates'])
        ->middleware('capability:recruitment.candidate.read')
        ->name('recruitment.candidates.index');
    Route::get('/candidates/{id}/evaluations', [RecruitmentFunnelController::class, 'evaluations'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.evaluation.manage')
        ->name('recruitment.candidates.evaluations.index');
    Route::post('/candidates/{id}/evaluations', [RecruitmentFunnelController::class, 'storeEvaluation'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:recruitment.evaluation.manage'])
        ->name('recruitment.candidates.evaluations.store');
    Route::get('/candidates/{id}/communications', [RecruitmentFunnelController::class, 'communications'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.communication.manage')
        ->name('recruitment.candidates.communications.index');
    Route::post('/candidates/{id}/communications', [RecruitmentFunnelController::class, 'storeCommunication'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:recruitment.communication.manage'])
        ->name('recruitment.candidates.communications.store');
    Route::get('/candidates/{id}/talent-pool', [RecruitmentFunnelController::class, 'talentPool'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.talent-pool.manage')
        ->name('recruitment.candidates.talent-pool.index');
    Route::post('/candidates/{id}/talent-pool', [RecruitmentFunnelController::class, 'storeTalentPoolTag'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:recruitment.talent-pool.manage'])
        ->name('recruitment.candidates.talent-pool.store');
    Route::post('/candidates/{id}/convert', [RecruitmentFunnelController::class, 'convert'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'mfa.enrolled', 'capability:recruitment.candidate.convert,COMPANY'])
        ->name('recruitment.candidates.convert');
    Route::get('/requisitions', [RecruitmentController::class, 'requisitions'])
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.requisitions.index');
    Route::post('/requisitions', [RecruitmentController::class, 'storeRequisition'])
        ->middleware('capability:recruitment.requisition.manage,COMPANY')
        ->name('recruitment.requisitions.store');
    Route::patch('/requisitions/{id}', [RecruitmentController::class, 'updateRequisition'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:recruitment.requisition.manage,COMPANY'])
        ->name('recruitment.requisitions.update');
    Route::post('/requisitions/{id}/close', [RecruitmentController::class, 'closeRequisition'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:recruitment.requisition.manage,COMPANY'])
        ->name('recruitment.requisitions.close');
    Route::patch('/candidates/{id}/stage', [RecruitmentController::class, 'moveCandidateStage'])
        ->whereNumber('id')
        ->middleware(['csrf.header', 'capability:recruitment.candidate.manage'])
        ->name('recruitment.candidates.stage');
    Route::get('/candidates/{id}/interviews', [RecruitmentController::class, 'interviews'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.interview.manage')
        ->name('recruitment.interviews.index');
    Route::get('/requisitions/{id}/openings', [App\Modules\Talent\Http\Controllers\RecruitmentOpeningController::class, 'index'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.openings.index');
    Route::post('/requisitions/{id}/openings', [App\Modules\Talent\Http\Controllers\RecruitmentOpeningController::class, 'store'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.requisition.manage')
        ->name('recruitment.openings.store');
    Route::get('/board', [RecruitmentFunnelController::class, 'board'])
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.board');
    Route::get('/budget', [RecruitmentFunnelController::class, 'budget'])
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.budget');
    Route::get('/reports', [RecruitmentFunnelController::class, 'reports'])
        ->middleware('capability:recruitment.requisition.read')
        ->name('recruitment.reports');
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
    Route::post('/candidates/{id}/applications', [RecruitmentController::class, 'storeApplication'])
        ->whereNumber('id')
        ->middleware('capability:recruitment.candidate.manage')
        ->name('recruitment.applications.store');
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
    Route::get('/reviews', [PerformanceController::class, 'reviews'])
        ->middleware('capability:performance.review.manage')
        ->name('performance.reviews.index');
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

    Route::get('/indicators', [PerformanceCycleController::class, 'indicators'])
        ->middleware('capability:performance.indicator.manage')
        ->name('performance.indicators.index');
    Route::post('/indicators', [PerformanceCycleController::class, 'storeIndicator'])
        ->middleware('capability:performance.indicator.manage')
        ->name('performance.indicators.store');
    Route::get('/goals', [PerformanceCycleController::class, 'goals'])
        ->middleware('capability:performance.goal.manage')
        ->name('performance.goals.index');
    Route::post('/goals', [PerformanceCycleController::class, 'storeGoal'])
        ->middleware('capability:performance.goal.manage')
        ->name('performance.goals.store');
    Route::get('/feedback', [PerformanceCycleController::class, 'feedback'])
        ->middleware('capability:performance.feedback.manage')
        ->name('performance.feedback.index');
    Route::post('/feedback', [PerformanceCycleController::class, 'storeFeedback'])
        ->middleware('capability:performance.feedback.manage')
        ->name('performance.feedback.store');
    Route::get('/calibration', [PerformanceCycleController::class, 'calibrations'])
        ->middleware('capability:performance.calibration.manage')
        ->name('performance.calibration.index');
    Route::post('/reviews/{id}/calibration', [PerformanceCycleController::class, 'storeCalibration'])
        ->whereNumber('id')
        ->middleware('capability:performance.calibration.manage')
        ->name('performance.reviews.calibration.store');
    Route::get('/results', [PerformanceCycleController::class, 'results'])
        ->middleware('capability:performance.result.publish')
        ->name('performance.results.index');
    Route::get('/reports', [PerformanceCycleController::class, 'reports'])
        ->middleware('capability:performance.report.read')
        ->name('performance.reports.index');
    Route::post('/cycles/{id}/publish', [PerformanceCycleController::class, 'publishCycle'])
        ->whereNumber('id')
        ->middleware('capability:performance.result.publish')
        ->name('performance.cycles.publish');
});

Route::prefix('training')->middleware(['auth.session', 'csrf.header'])->group(function (): void {
    Route::get('/plans', [TrainingController::class, 'plans'])
        ->middleware('capability:training.plan.manage,COMPANY')
        ->name('training.plans.index');
    Route::post('/plans', [TrainingController::class, 'storePlan'])
        ->middleware('capability:training.plan.manage,COMPANY')
        ->name('training.plans.store');
    Route::get('/courses', [TrainingController::class, 'courses'])
        ->middleware('capability:training.course.manage,COMPANY')
        ->name('training.courses.index');
    Route::post('/courses', [TrainingController::class, 'storeCourse'])
        ->middleware('capability:training.course.manage,COMPANY')
        ->name('training.courses.store');
    Route::get('/programs', [TrainingController::class, 'programs'])
        ->middleware('capability:training.program.read')
        ->name('training.programs.index');
    Route::post('/programs', [TrainingController::class, 'storeProgram'])
        ->middleware('capability:training.program.manage')
        ->name('training.programs.store');
    Route::patch('/programs/{id}', [TrainingController::class, 'updateProgram'])
        ->whereNumber('id')
        ->middleware('capability:training.program.manage')
        ->name('training.programs.update');
    Route::patch('/courses/{id}', [TrainingController::class, 'updateCourse'])
        ->whereNumber('id')
        ->middleware('capability:training.course.manage,COMPANY')
        ->name('training.courses.update');
    Route::patch('/sessions/{id}', [TrainingController::class, 'updateSession'])
        ->whereNumber('id')
        ->middleware('capability:training.session.manage')
        ->name('training.sessions.update');
    Route::post('/enrollments/{id}/cancel', [TrainingController::class, 'cancelEnrollment'])
        ->whereNumber('id')
        ->middleware('capability:training.enrollment.manage')
        ->name('training.enrollments.cancel');
    Route::get('/sessions', [TrainingController::class, 'sessions'])
        ->middleware('capability:training.session.manage')
        ->name('training.sessions.index');
    Route::post('/sessions', [TrainingController::class, 'storeSession'])
        ->middleware('capability:training.session.manage')
        ->name('training.sessions.store');
    Route::get('/sessions/{id}/checkins', [TrainingController::class, 'checkins'])
        ->whereNumber('id')
        ->middleware('capability:training.checkin.manage,COMPANY')
        ->name('training.sessions.checkins.index');
    Route::post('/sessions/{id}/checkins', [TrainingController::class, 'storeCheckin'])
        ->whereNumber('id')
        ->middleware('capability:training.checkin.manage,COMPANY')
        ->name('training.sessions.checkins.store');
    Route::get('/checkins', [TrainingController::class, 'checkins'])
        ->middleware('capability:training.checkin.manage,COMPANY')
        ->name('training.checkins.index');
    Route::get('/enrollments', [TrainingController::class, 'enrollments'])
        ->middleware('capability:training.enrollment.manage')
        ->name('training.enrollments.index');
    Route::post('/enrollments', [TrainingController::class, 'storeEnrollment'])
        ->middleware('capability:training.enrollment.manage')
        ->name('training.enrollments.store');
    Route::post('/enrollments/{id}/complete', [TrainingController::class, 'completeEnrollment'])
        ->whereNumber('id')
        ->middleware('capability:training.completion.manage')
        ->name('training.enrollments.complete');
    Route::get('/exams', [TrainingController::class, 'exams'])
        ->middleware('capability:training.exam.manage,COMPANY')
        ->name('training.exams.index');
    Route::post('/exams', [TrainingController::class, 'storeExam'])
        ->middleware('capability:training.exam.manage,COMPANY')
        ->name('training.exams.store');
    Route::post('/exams/{id}/publish', [TrainingController::class, 'publishExam'])
        ->whereNumber('id')
        ->middleware('capability:training.exam.manage,COMPANY')
        ->name('training.exams.publish');
    Route::get('/feedback', [TrainingController::class, 'feedback'])
        ->middleware('capability:training.feedback.manage,COMPANY')
        ->name('training.feedback.index');
    Route::post('/feedback', [TrainingController::class, 'storeFeedback'])
        ->middleware('capability:training.feedback.manage,COMPANY')
        ->name('training.feedback.store');
    Route::get('/records', [TrainingController::class, 'records'])
        ->middleware('capability:training.record.read,COMPANY')
        ->name('training.records.index');
    Route::get('/records/export', [TrainingController::class, 'exportRecords'])
        ->middleware('capability:training.record.read,COMPANY')
        ->name('training.records.export');
    Route::get('/reports', [TrainingController::class, 'reports'])
        ->middleware('capability:training.report.read')
        ->name('training.reports.index');
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
        ->middleware('capability:payroll.run.read,COMPANY')
        ->name('payroll.items.index');
    Route::post('/items', [PayrollCatalogController::class, 'storeItem'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,COMPANY')
        ->name('payroll.items.store');
    Route::get('/schemes', [PayrollCatalogController::class, 'schemes'])
        ->middleware('capability:payroll.run.read,COMPANY')
        ->name('payroll.schemes.index');
    Route::post('/items/{id}/versions', [PayrollCatalogController::class, 'storeVersion'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,COMPANY')
        ->name('payroll.items.versions.store');
    Route::post('/items/{id}/retire', [PayrollCatalogController::class, 'retire'])
        ->whereNumber('id')
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,COMPANY')
        ->name('payroll.items.retire');
    Route::get('/reports/summary', [PayrollReportController::class, 'summary'])
        ->middleware('capability:payroll.report.read,COMPANY')
        ->name('payroll.reports.summary');
    Route::post('/schemes', [PayrollCatalogController::class, 'storeScheme'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,COMPANY')
        ->name('payroll.schemes.store');

    // Payroll runs are PAYROLL_RUN-anchored. The guard only answers "may this
    // principal work with payroll runs at all"; the controller then restricts the
    // listing to runs the principal is a member of. A blanket COMPANY gate would
    // both deny every specialist and expose every run.
    Route::get('/runs', [PayrollRunController::class, 'index'])
        ->middleware('capability:payroll.run.read')
        ->name('payroll.runs.index');
    Route::post('/runs', [PayrollRunController::class, 'store'])
        ->middleware(IdempotentCommand::class)
        ->middleware('capability:payroll.run.prepare,COMPANY')
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
        ->middleware('capability:payroll.parameters.read,COMPANY')
        ->name('payroll.parameters.index');
    Route::post('/statutory-parameters', [PayrollStatutoryParameterController::class, 'store'])
        ->middleware(IdempotentCommand::class)
        ->middleware(['mfa.step-up', 'capability:payroll.parameters.manage,COMPANY'])
        ->name('payroll.parameters.store');

    // Effective-dated compensation archive and case-approved salary
    // adjustments. Reads use the ProjectionResponder envelope; writes use
    // CommandRunner (Idempotency-Key is required by the runner itself).
    Route::get('/employee-plans', [CompensationController::class, 'plans'])
        ->middleware('capability:payroll.compensation.read,COMPANY')
        ->name('payroll.employee-plans.index');
    Route::post('/employee-plans', [CompensationController::class, 'storePlan'])
        ->middleware('capability:payroll.compensation.manage,COMPANY')
        ->name('payroll.employee-plans.store');
    Route::get('/adjustments', [CompensationController::class, 'adjustments'])
        ->middleware('capability:payroll.compensation.read,COMPANY')
        ->name('payroll.adjustments.index');
    Route::post('/adjustments', [CompensationController::class, 'storeAdjustment'])
        ->middleware('capability:payroll.salary-adjustment.manage,COMPANY')
        ->name('payroll.adjustments.store');
});

/*
|--------------------------------------------------------------------------
| Benefits and statutory enrollment
|--------------------------------------------------------------------------
|
| Plans are effective-dated versions; enrollment movements and monthly cost
| ledger rows are append-only. Every write uses CommandRunner and therefore
| requires an Idempotency-Key. Writes separately require benefit.manage so a
| benefits administrator does not receive payroll authorization implicitly.
|
*/
Route::prefix('benefits')->middleware(['auth.session', 'csrf.header'])->group(function (): void {
    Route::get('/plans', [BenefitController::class, 'plans'])
        ->middleware(['mfa.enrolled', 'capability:benefit.read,SELF'])
        ->name('benefits.plans.index');
    Route::post('/plans', [BenefitController::class, 'storePlan'])
        ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:benefit.manage,COMPANY'])
        ->name('benefits.plans.store');
    Route::get('/enrollments', [BenefitController::class, 'enrollments'])
        ->middleware(['mfa.enrolled', 'capability:benefit.read,SELF'])
        ->name('benefits.enrollments.index');
    Route::post('/enrollments', [BenefitController::class, 'storeEnrollment'])
        ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:benefit.manage,COMPANY'])
        ->name('benefits.enrollments.store');
    Route::get('/transactions', [BenefitController::class, 'transactions'])
        ->middleware(['mfa.enrolled', 'capability:benefit.read,SELF'])
        ->name('benefits.transactions.index');
    Route::post('/transactions', [BenefitController::class, 'storeTransaction'])
        ->middleware(['mfa.enrolled', 'mfa.step-up', 'capability:benefit.manage,COMPANY'])
        ->name('benefits.transactions.store');
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
        ->middleware('capability:reporting.report.read')
        ->name('reporting.definitions.index');
    Route::post('/definitions', [ReportingController::class, 'createDefinition'])
        ->middleware(['mfa.step-up', 'capability:reporting.definition.manage,COMPANY'])
        ->name('reporting.definitions.store');
    Route::post('/run', [ReportingController::class, 'run'])
        ->middleware('capability:reporting.report.read')
        ->name('reporting.run');
    Route::get('/subscriptions', [ReportSubscriptionController::class, 'index'])
        ->middleware('capability:reporting.subscription.manage')
        ->name('reporting.subscriptions.index');
    Route::post('/subscriptions', [ReportSubscriptionController::class, 'store'])
        ->middleware(['mfa.step-up', 'capability:reporting.subscription.manage,COMPANY'])
        ->name('reporting.subscriptions.store');
    Route::patch('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'update'])
        ->whereNumber('subscriptionId')
        ->middleware(['mfa.step-up', 'capability:reporting.subscription.manage,COMPANY'])
        ->name('reporting.subscriptions.update');
    Route::delete('/subscriptions/{subscriptionId}', [ReportSubscriptionController::class, 'destroy'])
        ->whereNumber('subscriptionId')
        ->middleware(['mfa.step-up', 'capability:reporting.subscription.manage,COMPANY'])
        ->name('reporting.subscriptions.destroy');
    Route::get('/exports', [ReportingController::class, 'exports'])
        ->middleware('capability:reporting.export.read')
        ->name('reporting.exports.index');
    Route::post('/exports', [ReportingController::class, 'createExport'])
        ->middleware(['throttle:10,1', 'mfa.step-up', 'capability:reporting.export,COMPANY'])
        ->name('reporting.exports.store');
    Route::get('/exports/{jobId}/download', [ReportingController::class, 'download'])
        ->whereNumber('jobId')
        ->middleware('capability:reporting.export')
        ->name('reporting.exports.download');
});
