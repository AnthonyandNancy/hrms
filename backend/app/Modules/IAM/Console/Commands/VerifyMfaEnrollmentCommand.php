<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use Illuminate\Console\Command;

/**
 * `mfa_enrolled_at` must mean "a confirmed factor exists". The demo seeder used
 * to write the timestamp directly, producing a privileged account that looked
 * MFA-enrolled while having zero factors, so every step-up was impossible.
 */
final class VerifyMfaEnrollmentCommand extends Command
{
    protected $signature = 'iam:verify-mfa-enrollment';

    protected $description = 'Fail when mfa_enrolled_at disagrees with the confirmed factor set';

    public function handle(): int
    {
        $problems = [];

        $confirmedByUser = MfaFactor::query()
            ->whereNotNull('confirmed_at')
            ->whereNull('disabled_at')
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->all();

        foreach (User::query()->orderBy('id')->cursor() as $user) {
            $hasFactor = (int) ($confirmedByUser[(int) $user->id] ?? 0) > 0;
            $flagged = $user->mfa_enrolled_at !== null;

            if ($hasFactor !== $flagged) {
                $problems[] = sprintf(
                    '%s (id=%d) flagged=%s factors=%d',
                    $user->username,
                    (int) $user->id,
                    $flagged ? 'yes' : 'no',
                    $hasFactor ? 1 : 0,
                );
            }
        }

        if ($problems !== []) {
            $this->error('MFA enrollment invariant violated:');

            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info('MFA enrollment invariant holds.');

        return self::SUCCESS;
    }
}
