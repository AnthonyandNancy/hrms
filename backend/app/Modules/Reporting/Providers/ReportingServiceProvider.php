<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Providers;

use App\Modules\Lifecycle\Contracts\TurnoverDirectory;
use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\WorkforceReportingDirectory;
use App\Modules\Reporting\Contracts\ExportDispatcher;
use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\ExportJobRunner;
use App\Modules\Reporting\Services\ExportService;
use App\Modules\Reporting\Services\InlineExportDispatcher;
use App\Modules\Reporting\Services\MetricQueryBuilder;
use App\Modules\Reporting\Services\ProjectionBuilder;
use App\Modules\Reporting\Services\QueueExportDispatcher;
use App\Modules\Reporting\Services\ReportDefinitionCatalog;
use App\Modules\Reporting\Services\ReportService;
use App\Modules\Reporting\Services\ReportSubscriptionService;
use App\Modules\Talent\Contracts\RecruitmentFunnelDirectory;
use App\Modules\Time\Contracts\LeaveLiabilityDirectory;
use App\Modules\Workflow\Contracts\ReportingApprovalDirectory;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->bind(ProjectionBuilder::class, fn ($app): ProjectionBuilder => new ProjectionBuilder(
            $app->make(WorkforceReportingDirectory::class),
            $app->make(ReportingApprovalDirectory::class),
            $app->make(LeaveLiabilityDirectory::class),
            $app->make(PayrollCostDirectory::class),
            $app->make(RecruitmentFunnelDirectory::class),
            $app->make(TurnoverDirectory::class),
            $app->make(PeopleDirectory::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(MetricQueryBuilder::class, fn ($app): MetricQueryBuilder => new MetricQueryBuilder(
            $app->make(\App\Shared\Authorization\Authorizer::class),
            $app->make(ProjectionBuilder::class),
        ));

        $this->app->bind(DashboardService::class, fn ($app): DashboardService => new DashboardService(
            $app->make(MetricQueryBuilder::class),
            $app->make(\App\Shared\Authorization\Authorizer::class),
            $app->make(\App\Modules\Engagement\Contracts\NoticeWorkspaceDirectory::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(ReportDefinitionCatalog::class, fn (): ReportDefinitionCatalog => new ReportDefinitionCatalog);

        $this->app->bind(ReportService::class, fn ($app): ReportService => new ReportService(
            $app->make(ReportDefinitionCatalog::class),
            $app->make(MetricQueryBuilder::class),
            $app->make(ProjectionBuilder::class),
            $app->make(\App\Shared\Time\Clock::class),
            $app->make(\App\Shared\Authorization\Contracts\SensitiveAccessAuditor::class),
        ));

        $this->app->bind(ReportSubscriptionService::class, fn ($app): ReportSubscriptionService => new ReportSubscriptionService(
            $app->make(ReportService::class),
            $app->make(ReportDefinitionCatalog::class),
            $app->make(\App\Shared\Authorization\Authorizer::class),
            $app->make(\App\Shared\Authorization\Contracts\AccessSubjectStateDirectory::class),
            $app->make(\App\Shared\Observability\AuditRecorder::class),
            $app->make(\App\Shared\Time\TenantBusinessDate::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(ExportJobRunner::class, fn ($app): ExportJobRunner => new ExportJobRunner(
            $app->make(ReportService::class),
            $app->make(\App\Modules\Platform\Contracts\PrivateFileStore::class),
            $app->make(\App\Modules\Platform\Contracts\FileObjectReader::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(ExportDispatcher::class, function ($app): ExportDispatcher {
            if ($app->environment('testing')) {
                return new InlineExportDispatcher($app->make(ExportJobRunner::class));
            }

            return new QueueExportDispatcher;
        });

        $this->app->bind(ExportService::class, fn ($app): ExportService => new ExportService(
            $app->make(ReportService::class),
            $app->make(ReportDefinitionCatalog::class),
            $app->make(ExportJobRunner::class),
            $app->make(ExportDispatcher::class),
            $app->make(\App\Shared\Observability\AuditRecorder::class),
            $app->make(\App\Shared\Authorization\Authorizer::class),
            $app->make(\App\Shared\Time\TenantBusinessDate::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));
    }
}
