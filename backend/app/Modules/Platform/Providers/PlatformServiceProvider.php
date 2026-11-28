<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Contracts\DictionaryReader;
use App\Modules\Platform\Contracts\FileObjectReader;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Platform\Contracts\InstallationStateStore;
use App\Modules\Platform\Contracts\MenuOverrideReader;
use App\Modules\Platform\Contracts\MenuOverrideWriter;
use App\Modules\Platform\Contracts\NotificationDeliveryLedger;
use App\Modules\Platform\Contracts\NotificationTransport;
use App\Modules\Platform\Contracts\OutboxDispatcher;
use App\Modules\Platform\Contracts\ParameterReader;
use App\Modules\Platform\Contracts\PrivateFileStore;
use App\Modules\Platform\Migration\Adapters\DatabaseMigrationTarget;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Services\MigrationTargetRegistry;
use App\Modules\Platform\Migration\Services\ShadowMigrationTarget;
use App\Modules\Platform\Services\AuditRecorderAdapter;
use App\Modules\Platform\Services\DatabaseDictionaryReader;
use App\Modules\Platform\Services\DatabaseParameterReader;
use App\Modules\Platform\Services\EloquentFileObjectReader;
use App\Modules\Platform\Services\EloquentInstallationStateStore;
use App\Modules\Platform\Services\EloquentNotificationDeliveryLedger;
use App\Modules\Platform\Services\FileMetadataAdapter;
use App\Modules\Platform\Services\LocalPrivateFileStore;
use App\Modules\Platform\Services\UnavailableNotificationTransport;
use App\Modules\Platform\Services\LogOutboxDispatcher;
use App\Modules\Platform\Services\MenuOverrideService;
use App\Modules\Platform\Services\OutboxRecorderAdapter;
use App\Shared\Files\FileMetadataReader;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OutboxDispatcher::class, LogOutboxDispatcher::class);
        $this->app->bind(InstallationStateStore::class, EloquentInstallationStateStore::class);
        $this->app->bind(AuditRecorder::class, AuditRecorderAdapter::class);
        $this->app->bind(OutboxRecorder::class, OutboxRecorderAdapter::class);
        $this->app->bind(IdempotencyRunner::class, \App\Modules\Platform\Services\IdempotencyService::class);
        $this->app->bind(DictionaryReader::class, DatabaseDictionaryReader::class);
        $this->app->bind(ParameterReader::class, DatabaseParameterReader::class);
        $this->app->bind(FileMetadataReader::class, FileMetadataAdapter::class);
        $this->app->bind(PrivateFileStore::class, LocalPrivateFileStore::class);
        $this->app->bind(FileObjectReader::class, EloquentFileObjectReader::class);
        $this->app->bind(MenuOverrideReader::class, MenuOverrideService::class);
        $this->app->bind(MenuOverrideWriter::class, MenuOverrideService::class);
        $this->app->bind(NotificationDeliveryLedger::class, EloquentNotificationDeliveryLedger::class);
        $this->app->bind(NotificationTransport::class, UnavailableNotificationTransport::class);

        $this->app->singleton(MigrationTargetRegistry::class, function (): MigrationTargetRegistry {
            $registry = new MigrationTargetRegistry;

            foreach (MigrationDomain::cases() as $domain) {
                $registry->register(new DatabaseMigrationTarget($domain));
                $registry->register(new ShadowMigrationTarget($domain), shadow: true);
            }

            return $registry;
        });
    }
}
