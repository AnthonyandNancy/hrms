<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\AuthEvent;
use App\Modules\IAM\Models\LoginAttempt;
use App\Modules\IAM\Models\User;
use App\Shared\Time\Clock;

/**
 * Read-only helper for demo/bootstrap commands and login policy checks.
 */
final class LoginService
{
    public function __construct(private readonly Clock $clock) {}

    public function recentFailureCount(string $username, string $ip): int
    {
        return LoginAttempt::query()
            ->where('username', $username)
            ->where('ip', $ip)
            ->where('succeeded', false)
            ->where('attempted_at', '>=', $this->clock->now()->subMinute())
            ->count();
    }

    public function isLocked(string $username): bool
    {
        return LoginAttempt::query()
            ->where('username', $username)
            ->where('succeeded', false)
            ->where('attempted_at', '>=', $this->clock->now()->subMinutes((int) config('iam.login.failure_window_minutes', 15)))
            ->count() >= (int) config('iam.login.max_failures', 10);
    }
}
