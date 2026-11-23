<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Enums\CaseEffectStatus;
use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use Illuminate\Console\Command;

/**
 * Invariant check for the approval/effect separation. Exits non-zero only for
 * missing effect rows on completed cases, which is the data-loss invariant;
 * long-pending effects are warning-only because they are legitimate state.
 */
final class CheckWorkflowCommand extends Command
{
    protected $signature = 'workflow:check {--effect-sla-hours=24 : Warn when approved effects wait longer}';

    protected $description = 'Verify every approved case has persisted effect rows and report stale effects';

    public function handle(): int
    {
        $violations = 0;
        $warnings = 0;

        $approved = CaseModel::query()
            ->where('status', CaseStatus::APPROVED->value)
            ->whereIn('effect_status', [
                CaseEffectStatus::NONE->value,
                CaseEffectStatus::PENDING->value,
            ])
            ->get();

        foreach ($approved as $case) {
            if (Effect::query()->where('case_id', $case->id)->exists()) {
                $warnings++;
                $this->warn("Case {$case->case_no} is APPROVED with effect_status {$case->effect_status->value}.");

                continue;
            }

            $violations++;
            $this->error("Case {$case->case_no} is APPROVED but has no effect rows.");
        }

        $slaHours = max(1, (int) $this->option('effect-sla-hours'));
        $threshold = now('UTC')->subHours($slaHours);

        $stale = Effect::query()
            ->where('status', \App\Modules\Workflow\Enums\EffectStatus::SCHEDULED->value)
            ->where('created_at', '<', $threshold)
            ->count();

        if ($stale > 0) {
            $warnings++;
            $this->warn("{$stale} scheduled effect(s) have been pending for more than {$slaHours} hour(s).");
        }

        $this->info("workflow:check completed with {$violations} violation(s) and {$warnings} warning(s).");

        return $violations === 0 ? self::SUCCESS : self::FAILURE;
    }
}
