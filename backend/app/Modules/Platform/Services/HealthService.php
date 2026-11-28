<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\InstallationStateStore;
use App\Modules\Platform\Models\PlatformInstallationState;
use App\Shared\Health\ActiveAdministratorDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health probes are intentionally isolated from the module's controllers so
 * deployments and tests can replace individual capability checks.
 */
class HealthService
{
    public function __construct(
        private readonly InstallationStateStore $installations,
        private readonly ActiveAdministratorDirectory $administrators,
    ) {}

    public function databaseHealthy(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function cacheHealthy(): bool
    {
        try {
            $key = 'health:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 5);
            $healthy = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $healthy;
        } catch (Throwable) {
            return false;
        }
    }

    public function storageHealthy(): bool
    {
        try {
            $disk = Storage::disk(config('filesystems.default', 'local'));
            $path = 'health/'.bin2hex(random_bytes(8)).'.txt';

            return $disk->put($path, 'ok') && $disk->delete($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function migrationsHealthy(): bool
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return false;
            }

            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->getRepository()->getRan();

            return array_diff(array_keys($files), $ran) === [];
        } catch (Throwable) {
            return false;
        }
    }

    public function installationHealthy(): bool
    {
        try {
            $state = $this->installations->current();

            return $state !== null
                && $state->status === PlatformInstallationState::STATUS_COMPLETED
                && $state->adminUserId !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function administratorHealthy(): bool
    {
        try {
            return $this->administrators->count() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function version(): string
    {
        return (string) config('app.version', '1.0.0');
    }
}
