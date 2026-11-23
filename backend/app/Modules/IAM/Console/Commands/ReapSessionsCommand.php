<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\Session;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class ReapSessionsCommand extends Command
{
    protected $signature = 'iam:reap-sessions';

    protected $description = 'Revoke sessions past their idle or absolute expiry.';

    public function handle(Clock $clock): int
    {
        $now = $clock->now();

        $count = Session::query()
            ->whereNull('revoked_at')
            ->where(function ($query) use ($now): void {
                $query->where('idle_expires_at', '<=', $now)->orWhere('absolute_expires_at', '<=', $now);
            })
            ->update(['revoked_at' => $now]);

        $this->info("Revoked {$count} expired session(s).");

        return self::SUCCESS;
    }
}
