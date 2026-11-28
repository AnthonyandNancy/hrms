<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Adapters\NoticeApprovalWorkflowAdapter;
use App\Modules\Engagement\Adapters\PayslipPublicationNotifierAdapter;
use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Modules\Engagement\Contracts\NoticeApprovalPort;
use App\Modules\Engagement\Contracts\NoticeWorkspaceDirectory;
use App\Modules\Engagement\Services\EloquentNoticeWorkspaceDirectory;
use App\Modules\Engagement\Services\NoticeAccessPolicy;
use App\Modules\Engagement\Services\NoticeAudienceService;
use App\Modules\Engagement\Services\NoticeGovernanceService;
use App\Modules\Engagement\Services\NoticeService;
use App\Modules\Engagement\Services\NotificationService;
use App\Modules\Engagement\Services\PayslipNotificationService;
use App\Modules\Engagement\Services\UnavailableExternalNotificationChannel;
use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\Payroll\Contracts\PayslipPublicationNotifier;
use App\Modules\People\Contracts\NoticeAudienceDirectory;
use App\Shared\Notifications\NotificationDeliveryRetry;
use Illuminate\Support\ServiceProvider;

final class EngagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ExternalNotificationChannel::class, UnavailableExternalNotificationChannel::class);
        $this->app->bind(PayslipPublicationNotifier::class, PayslipPublicationNotifierAdapter::class);
        $this->app->bind(NoticeWorkspaceDirectory::class, EloquentNoticeWorkspaceDirectory::class);

        $this->app->bind(NotificationDeliveryRetry::class, NotificationService::class);
        $this->app->bind(NotificationService::class, fn ($app): NotificationService => new NotificationService(
            $app->make(ExternalNotificationChannel::class),
            $app->make(\App\Shared\Observability\OutboxRecorder::class),
            $app->make(\App\Shared\Time\Clock::class),
            null,
            $app->make(\App\Modules\Platform\Contracts\NotificationDeliveryLedger::class),
            $app->make(\App\Modules\People\Contracts\PeopleDirectory::class),
            $app->make(\App\Shared\Time\CompanyBusinessDate::class),
        ));

        $this->app->bind(PayslipNotificationService::class, fn ($app): PayslipNotificationService => new PayslipNotificationService(
            $app->make(\App\Modules\Payroll\Contracts\PayslipNotificationDirectory::class),
            $app->make(NotificationService::class),
            $app->make(NoticeAudienceDirectory::class),
            $app->make(\App\Shared\Time\CompanyBusinessDate::class),
        ));

        $this->app->bind(NoticeAudienceService::class, fn ($app): NoticeAudienceService => new NoticeAudienceService(
            $app->make(\App\Modules\Organization\Contracts\OrganizationDirectory::class),
            $app->make(NoticeAudienceDirectory::class),
            $app->make(UserAudienceDirectory::class),
            $app->make(\App\Shared\Time\CompanyBusinessDate::class),
        ));

        $this->app->bind(NoticeAccessPolicy::class, fn ($app): NoticeAccessPolicy => new NoticeAccessPolicy(
            $app->make(\App\Shared\Authorization\Authorizer::class),
            $app->make(\App\Modules\People\Contracts\PeopleDirectory::class),
            $app->make(NoticeAudienceDirectory::class),
            $app->make(UserAudienceDirectory::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(NoticeService::class, fn ($app): NoticeService => new NoticeService(
            $app->make(NoticeAudienceService::class),
            $app->make(NoticeAccessPolicy::class),
            $app->make(NotificationService::class),
            $app->make(\App\Shared\Time\CompanyBusinessDate::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(NoticeApprovalPort::class, fn ($app): NoticeApprovalWorkflowAdapter => new NoticeApprovalWorkflowAdapter(
            $app->make(\App\Modules\Workflow\Contracts\CaseCommandPort::class),
            $app->make(\App\Modules\Workflow\Contracts\CaseDecisionPort::class),
            $app->make(\App\Modules\Workflow\Contracts\WorkflowCaseGateway::class),
        ));

        $this->app->bind(NoticeGovernanceService::class, fn ($app): NoticeGovernanceService => new NoticeGovernanceService(
            $app->make(NoticeAudienceService::class),
            $app->make(NoticeAccessPolicy::class),
            $app->make(NotificationService::class),
            $app->make(NoticeApprovalPort::class),
            $app->make(\App\Shared\Observability\AuditRecorder::class),
            $app->make(\App\Shared\Authorization\Contracts\SensitiveAccessAuditor::class),
            $app->make(\App\Shared\Time\CompanyBusinessDate::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));
    }
}
