<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Adapters\PeopleEmploymentDirectory;
use App\Modules\Time\Adapters\WorkflowCaseAdapter;
use App\Modules\Time\Console\SeedCalendarCommand;
use App\Modules\Time\Console\SeedLeaveTypesCommand;
use App\Modules\Time\Contracts\AttendanceWorkspaceReader;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\LeaveBalanceReader;
use App\Modules\Time\Contracts\LeaveLiabilityDirectory;
use App\Modules\Time\Contracts\LeaveRequestReader;
use App\Modules\Time\Contracts\LegalEntityCalendarDirectory;
use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\EffectHandlers\LeaveApprovalEffectHandler;
use App\Modules\Time\Services\AttendanceWorkspaceReaderService;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\EloquentLeaveLiabilityDirectory;
use App\Modules\Time\Services\LeaveBalanceReaderService;
use App\Modules\Time\Services\LeaveRequestReaderService;
use App\Modules\Time\Services\PayrollInputDirectoryService;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Time owns attendance and the leave ledger. Payroll and Reporting consume
 * only narrow Time\Contracts ports; they never read Time tables directly.
 */
final class TimeServiceProvider extends ServiceProvider
{
    /** @var list<class-string<DomainEffectHandler>> */
    public const EFFECT_HANDLERS = [
        LeaveApprovalEffectHandler::class,
    ];

    /** @var list<string> */
    public const EFFECT_TYPES = [
        LeaveApprovalEffectHandler::APPLY,
        LeaveApprovalEffectHandler::REVERSE,
        LeaveApprovalEffectHandler::PENDING_RELEASE,
    ];

    public function register(): void
    {
        $this->app->bind(EmploymentDirectory::class, function (Application $app): PeopleEmploymentDirectory {
            return new PeopleEmploymentDirectory($app->make(PeopleDirectory::class));
        });

        $this->app->bind(LegalEntityCalendarDirectory::class, BusinessCalendar::class);

        $this->app->bind(WorkflowCasePort::class, function (Application $app): WorkflowCaseAdapter {
            return new WorkflowCaseAdapter(
                $app->make(CaseCommandPort::class),
                $app->make(WorkflowPrincipalResolver::class),
            );
        });

        $this->app->bind(AttendanceWorkspaceReader::class, AttendanceWorkspaceReaderService::class);
        $this->app->bind(LeaveBalanceReader::class, LeaveBalanceReaderService::class);
        $this->app->bind(LeaveRequestReader::class, LeaveRequestReaderService::class);
        $this->app->bind(PayrollInputDirectory::class, PayrollInputDirectoryService::class);
        $this->app->bindIf(LeaveLiabilityDirectory::class, EloquentLeaveLiabilityDirectory::class);

        /** @var list<class-string<DomainEffectHandler>> $existingHandlers */
        $existingHandlers = (array) config('workflow.domain_effect_handlers', []);
        config()->set('workflow.domain_effect_handlers', array_values(array_unique([
            ...$existingHandlers,
            ...self::EFFECT_HANDLERS,
        ])));

        /** @var list<string> $existingTypes */
        $existingTypes = (array) config('workflow.effect_types', []);
        config()->set('workflow.effect_types', array_values(array_unique([
            ...$existingTypes,
            ...self::EFFECT_TYPES,
        ])));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedLeaveTypesCommand::class,
                SeedCalendarCommand::class,
            ]);
        }
    }
}
