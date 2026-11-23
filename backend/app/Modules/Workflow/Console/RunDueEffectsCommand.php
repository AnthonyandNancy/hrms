<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Services\EffectExecutor;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class RunDueEffectsCommand extends Command
{
    protected $signature = 'effects:run-due {--limit=100 : Maximum effects to process}';

    protected $description = 'Apply scheduled workflow effects that are due today or overdue';

    public function handle(EffectExecutor $executor, Clock $clock): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $today = $clock->now()->toDateString();

        $effects = Effect::query()
            ->where('status', EffectStatus::SCHEDULED->value)
            ->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', '<=', $today)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $applied = 0;
        $failed = 0;

        foreach ($effects as $effect) {
            $result = $executor->apply($effect->tenant_id, $effect->id);

            if ($result->status === EffectStatus::APPLIED) {
                $applied++;
            }

            if ($result->status === EffectStatus::FAILED) {
                $failed++;
            }
        }

        $this->info("Processed {$effects->count()} due effect(s): {$applied} applied, {$failed} failed.");

        return self::SUCCESS;
    }
}
