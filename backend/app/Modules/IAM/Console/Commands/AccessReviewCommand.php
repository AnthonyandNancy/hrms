<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Shared\Authorization\SodRules;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class AccessReviewCommand extends Command
{
    protected $signature = 'iam:access-review {--quarter= : Quarter in YYYY-Qn format}';

    protected $description = 'Report high-risk bindings unused for 90 days and SOD conflicts.';

    public function handle(Clock $clock, CapabilityCatalog $catalog): int
    {
        $quarter = (string) $this->option('quarter');

        if (preg_match('/^\d{4}-Q[1-4]$/', $quarter) !== 1) {
            $this->error('Use --quarter=YYYY-Qn, for example --quarter=2026-Q4.');

            return self::FAILURE;
        }

        $cutoff = $clock->now()->subDays(90);
        $highRiskCodes = array_values(array_map(
            static fn ($definition): string => $definition->code,
            array_filter(
                $catalog->all(),
                static fn ($definition): bool => in_array($definition->risk, ['high', 'critical'], true),
            ),
        ));

        $unused = AuthorityBinding::query()
            ->whereIn('capability_code', $highRiskCodes)
            ->where('created_at', '<', $cutoff)
            ->where('valid_to', '>', $clock->now())
            ->orderBy('id')
            ->get()
            ->map(static fn (AuthorityBinding $binding): array => [
                'binding_id' => (int) $binding->id,
                'principal_id' => (int) $binding->principal_id,
                'capability_code' => $binding->capability_code,
                'risk' => $catalog->find($binding->capability_code)?->risk,
                'created_at' => $binding->created_at?->toIso8601ZuluString(),
            ])
            ->all();

        $byPrincipal = [];
        foreach (AuthorityBinding::query()->where('valid_to', '>', $clock->now())->get() as $binding) {
            $byPrincipal[(int) $binding->principal_id][] = $binding->capability_code;
        }

        $conflicts = [];
        foreach ($byPrincipal as $principalId => $codes) {
            foreach (SodRules::conflicts($codes) as $pair) {
                $conflicts[] = ['principal_id' => $principalId, 'capabilities' => $pair];
            }
        }

        $this->line((string) json_encode([
            'quarter' => $quarter,
            'generated_at' => $clock->now()->toIso8601ZuluString(),
            'unused_high_risk_bindings' => $unused,
            'sod_conflicts' => $conflicts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
