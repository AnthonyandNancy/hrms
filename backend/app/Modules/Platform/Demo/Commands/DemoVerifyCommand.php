<?php

declare(strict_types=1);

namespace App\Modules\Platform\Demo\Commands;

use App\Modules\Platform\Demo\Services\DemoVerifier;
use Illuminate\Console\Command;

class DemoVerifyCommand extends Command
{
    protected $signature = 'demo:verify
        {--tenant=1 : Tenant id}
        {--json : Print JSON}';

    protected $description = 'Assert demo dataset invariants and print the walkthrough checklist';

    public function handle(DemoVerifier $verifier): int
    {
        $result = $verifier->verify((int) $this->option('tenant'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Check', 'Result', 'Detail'],
                array_map(static fn (array $check): array => [
                    $check['name'],
                    $check['passed'] ? 'PASS' : 'FAIL',
                    $check['detail'],
                ], $result['checks']),
            );

            $this->line('Walkthrough:');

            foreach ($result['walkthrough'] as $index => $step) {
                $this->line(sprintf('  %d. %s', $index + 1, $step));
            }

            if ($result['deferred'] !== []) {
                $this->warn('Deferred domains: '.implode(', ', array_keys($result['deferred'])));
            }
        }

        $this->line(($result['passed'] ? 'PASS' : 'FAIL').': demo:verify mode='.$result['mode']);

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
