<?php

declare(strict_types=1);

namespace App\Modules\Platform\Features\Commands;

use App\Modules\Platform\Features\FeatureFlagService;
use Illuminate\Console\Command;

class SetFeatureFlagCommand extends Command
{
    protected $signature = 'feature:set
        {flag : Feature flag name}
        {state : on|off}
        {--reason= : Change reason (at least 8 characters)}
        {--actor= : Acting user id for the audit record}';

    protected $description = 'Toggle a server-enforced feature flag and write an audit record';

    public function handle(FeatureFlagService $flags): int
    {
        $flag = (string) $this->argument('flag');
        $state = strtolower((string) $this->argument('state'));
        $reason = trim((string) $this->option('reason'));

        if (! in_array($flag, $flags->flags(), true)) {
            $this->error('PASS/FAIL: FAIL - unknown flag. Known: '.implode(', ', $flags->flags()));

            return self::INVALID;
        }

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('PASS/FAIL: FAIL - state must be on or off');

            return self::INVALID;
        }

        if (strlen($reason) < 8) {
            $this->error('PASS/FAIL: FAIL - --reason must be at least 8 characters');

            return self::INVALID;
        }

        $actor = $this->option('actor');
        $flags->set($flag, $state === 'on', $actor === null ? null : (int) $actor, $reason);

        $this->line(sprintf(
            'PASS: feature:set %s=%s audited=yes',
            $flag,
            $flags->enabled($flag) ? 'on' : 'off',
        ));

        return self::SUCCESS;
    }
}
