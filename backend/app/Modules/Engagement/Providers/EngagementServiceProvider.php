<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Contracts\ExternalNotificationChannel;
use App\Modules\Engagement\Services\LogExternalNotificationChannel;
use App\Modules\Engagement\Services\NoticeAccessPolicy;
use App\Modules\Engagement\Services\NoticeAudienceService;
use App\Modules\Engagement\Services\NoticeService;
use App\Modules\Engagement\Services\NotificationService;
use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\People\Contracts\NoticeAudienceDirectory;
use Illuminate\Support\ServiceProvider;

final class EngagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ExternalNotificationChannel::class, LogExternalNotificationChannel::class);

        $this->app->bind(NotificationService::class, fn ($app): NotificationService => new NotificationService(
            $app->make(ExternalNotificationChannel::class),
            $app->make(\App\Shared\Observability\OutboxRecorder::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));

        $this->app->bind(NoticeAudienceService::class, fn ($app): NoticeAudienceService => new NoticeAudienceService(
            $app->make(\App\Modules\Organization\Contracts\OrganizationDirectory::class),
            $app->make(NoticeAudienceDirectory::class),
            $app->make(UserAudienceDirectory::class),
            $app->make(\App\Shared\Time\TenantBusinessDate::class),
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
            $app->make(\App\Shared\Authorization\Authorizer::class),
            $app->make(\App\Shared\Time\TenantBusinessDate::class),
            $app->make(\App\Shared\Time\Clock::class),
        ));
    }
}
