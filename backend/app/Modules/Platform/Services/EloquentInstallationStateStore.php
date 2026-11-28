<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\InstallationStateSnapshot;
use App\Modules\Platform\Contracts\InstallationStateStore;
use App\Modules\Platform\Models\PlatformInstallationState;
use Illuminate\Support\Facades\DB;

final class EloquentInstallationStateStore implements InstallationStateStore
{
    public function current(): ?InstallationStateSnapshot
    {
        $state = PlatformInstallationState::query()
            ->where('instance_key', PlatformInstallationState::INSTANCE_KEY)
            ->first();

        return $state instanceof PlatformInstallationState ? $this->snapshot($state) : null;
    }

    public function begin(string $adminUsername, string $applicationVersion): InstallationStateSnapshot
    {
        return DB::transaction(function () use ($adminUsername, $applicationVersion): InstallationStateSnapshot {
            $state = PlatformInstallationState::query()
                ->where('instance_key', PlatformInstallationState::INSTANCE_KEY)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                $state = PlatformInstallationState::query()->create([
                    'instance_key' => PlatformInstallationState::INSTANCE_KEY,
                    'status' => PlatformInstallationState::STATUS_PENDING,
                    'admin_username' => mb_strtolower(trim($adminUsername)),
                    'application_version' => $applicationVersion,
                    'started_at' => now('UTC'),
                ]);
            } elseif ($state->status !== PlatformInstallationState::STATUS_COMPLETED) {
                $state->forceFill([
                    'status' => PlatformInstallationState::STATUS_PENDING,
                    'admin_username' => mb_strtolower(trim($adminUsername)),
                    'application_version' => $applicationVersion,
                    'last_error' => null,
                    'started_at' => $state->started_at ?? now('UTC'),
                ])->save();
            }

            return $this->snapshot($state->refresh());
        });
    }

    public function complete(int $adminUserId, string $catalogHash): InstallationStateSnapshot
    {
        return DB::transaction(function () use ($adminUserId, $catalogHash): InstallationStateSnapshot {
            $state = PlatformInstallationState::query()
                ->where('instance_key', PlatformInstallationState::INSTANCE_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            if ($state->status !== PlatformInstallationState::STATUS_COMPLETED) {
                $state->forceFill([
                    'status' => PlatformInstallationState::STATUS_COMPLETED,
                    'admin_user_id' => $adminUserId,
                    'catalog_hash' => $catalogHash,
                    'last_error' => null,
                    'completed_at' => now('UTC'),
                ])->save();
            }

            return $this->snapshot($state->refresh());
        });
    }

    public function fail(string $reason): InstallationStateSnapshot
    {
        return DB::transaction(function () use ($reason): InstallationStateSnapshot {
            $state = PlatformInstallationState::query()
                ->where('instance_key', PlatformInstallationState::INSTANCE_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $state->forceFill([
                'last_error' => mb_substr($reason, 0, 4000),
            ])->save();

            return $this->snapshot($state->refresh());
        });
    }

    private function snapshot(PlatformInstallationState $state): InstallationStateSnapshot
    {
        return new InstallationStateSnapshot(
            status: (string) $state->status,
            adminUsername: (string) $state->admin_username,
            adminUserId: $state->admin_user_id !== null ? (int) $state->admin_user_id : null,
            applicationVersion: $state->application_version !== null ? (string) $state->application_version : null,
            catalogHash: $state->catalog_hash !== null ? (string) $state->catalog_hash : null,
            startedAt: $state->started_at?->toIso8601ZuluString(),
            completedAt: $state->completed_at?->toIso8601ZuluString(),
            lastError: $state->last_error !== null ? (string) $state->last_error : null,
        );
    }
}
