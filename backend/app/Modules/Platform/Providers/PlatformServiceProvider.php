<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Contracts\OutboxDispatcher;
use App\Modules\Platform\Migration\Adapters\DatabaseMigrationTarget;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Services\MigrationTargetRegistry;
use App\Modules\Platform\Migration\Services\ShadowMigrationTarget;
use App\Modules\Platform\Services\AuditRecorderAdapter;
use App\Modules\Platform\Services\FileMetadataAdapter;
use App\Modules\Platform\Services\LogOutboxDispatcher;
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
        $this->app->bind(AuditRecorder::class, AuditRecorderAdapter::class);
        $this->app->bind(OutboxRecorder::class, OutboxRecorderAdapter::class);
        $this->app->bind(FileMetadataReader::class, FileMetadataAdapter::class);

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
