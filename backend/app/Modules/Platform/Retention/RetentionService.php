<?php

declare(strict_types=1);

namespace App\Modules\Platform\Retention;

use App\Modules\Platform\Models\AuditEvent;
use App\Shared\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Preview and purge are deliberately separate. Preview is a pure read; purge
 * is dry-run by default, requires `confirm: true`, skips every row covered by
 * an active legal hold, and writes an audit event for each execution. Tables
 * that do not exist yet fail closed with an explanatory result rather than
 * silently reporting "0 purged".
 */
final class RetentionService
{
    /**
     * @return array{retention_class: string, label: string, table: string, available: bool, cutoff: string|null, permanent: bool, candidates: int, held: int, purgeable: int, sample_ids: list<string>}
     */
    public function preview(string $class, int $tenantId, ?CarbonImmutable $asOf = null): array
    {
        $definition = RetentionClass::get($class);
        $asOf ??= CarbonImmutable::now('UTC');
        $cutoff = $this->cutoff($definition, $asOf);
        $available = Schema::hasTable($definition['table'])
            && Schema::hasColumn($definition['table'], $definition['timestamp_column'])
            && Schema::hasColumn($definition['table'], 'tenant_id');

        if (! $available) {
            return [
                'retention_class' => $class,
                'label' => $definition['label'],
                'table' => $definition['table'],
                'available' => false,
                'cutoff' => $cutoff?->format('Y-m-d'),
                'permanent' => $definition['permanent'],
                'candidates' => 0,
                'held' => 0,
                'purgeable' => 0,
                'sample_ids' => [],
                'note' => "Target table {$definition['table']} is not present in this build; purge fails closed.",
            ];
        }

        if ($definition['permanent']) {
            return [
                'retention_class' => $class,
                'label' => $definition['label'],
                'table' => $definition['table'],
                'available' => true,
                'cutoff' => null,
                'permanent' => true,
                'candidates' => 0,
                'held' => 0,
                'purgeable' => 0,
                'sample_ids' => [],
                'note' => 'Permanent records are never purged by this command.',
            ];
        }

        $query = DB::table($definition['table'])
            ->where('tenant_id', $tenantId)
            ->whereNotNull($definition['timestamp_column'])
            ->where($definition['timestamp_column'], '<', $cutoff);
        $candidateIds = $query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $heldIds = $this->heldIds($tenantId, $class, $definition['table'], $candidateIds);
        $purgeable = array_values(array_diff($candidateIds, $heldIds));

        return [
            'retention_class' => $class,
            'label' => $definition['label'],
            'table' => $definition['table'],
            'available' => true,
            'cutoff' => $cutoff?->format('Y-m-d'),
            'permanent' => false,
            'candidates' => count($candidateIds),
            'held' => count($heldIds),
            'purgeable' => count($purgeable),
            'sample_ids' => array_slice($purgeable, 0, 10),
        ];
    }

    /**
     * @return array{preview: array<string, mixed>, executed: bool, purged: int, run_id: int|null}
     */
    public function purge(
        string $class,
        int $tenantId,
        ?CarbonImmutable $asOf = null,
        bool $confirm = false,
        ?int $actorUserId = null,
    ): array {
        $preview = $this->preview($class, $tenantId, $asOf);

        if (! $confirm) {
            return ['preview' => $preview, 'executed' => false, 'purged' => 0, 'run_id' => null];
        }

        if (! $preview['available']) {
            throw new RuntimeException((string) ($preview['note'] ?? 'Retention target is unavailable.'));
        }

        if ($preview['permanent']) {
            throw new RuntimeException('Permanent records cannot be purged.');
        }

        $run = RetentionPurgeRun::query()->create([
            'tenant_id' => $tenantId,
            'retention_class' => $class,
            'mode' => 'PURGE',
            'status' => 'RUNNING',
            'candidates' => $preview['candidates'],
            'held' => $preview['held'],
            'started_at' => CarbonImmutable::now('UTC'),
        ]);

        $definition = RetentionClass::get($class);
        $cutoff = $this->cutoff($definition, $asOf ?? CarbonImmutable::now('UTC'));
        $candidateIds = DB::table($definition['table'])
            ->where('tenant_id', $tenantId)
            ->whereNotNull($definition['timestamp_column'])
            ->where($definition['timestamp_column'], '<', $cutoff)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $heldIds = $this->heldIds($tenantId, $class, $definition['table'], $candidateIds);
        $purgeable = array_values(array_diff($candidateIds, $heldIds));

        $purged = DB::transaction(function () use ($purgeable, $definition, $tenantId): int {
            if ($purgeable === []) {
                return 0;
            }

            return DB::table($definition['table'])
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $purgeable)
                ->delete();
        });

        $run->forceFill([
            'status' => 'COMPLETED',
            'purged' => $purged,
            'finished_at' => CarbonImmutable::now('UTC'),
            'report_json' => [
                'cutoff' => $cutoff->format('Y-m-d'),
                'held' => count($heldIds),
                'purged_ids' => $purgeable,
            ],
        ])->save();

        AuditEvent::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorUserId === null ? 'SYSTEM' : 'USER',
            'action' => 'retention.purged',
            'aggregate_type' => 'retention_class',
            'aggregate_id' => $class,
            'after_json' => [
                'run_id' => (int) $run->id,
                'candidates' => $preview['candidates'],
                'held_skipped' => count($heldIds),
                'purged' => $purged,
            ],
            'metadata_json' => [
                'request_id' => RequestContext::requestId(),
                'table' => $definition['table'],
            ],
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);

        return ['preview' => $preview, 'executed' => true, 'purged' => $purged, 'run_id' => (int) $run->id];
    }

    /**
     * @param  array{label: string, table: string, timestamp_column: string, period_months: int|null, permanent: bool, owner: string}  $definition
     */
    private function cutoff(array $definition, CarbonImmutable $asOf): ?CarbonImmutable
    {
        if ($definition['permanent'] || $definition['period_months'] === null) {
            return null;
        }

        return $asOf->subMonths($definition['period_months'])->startOfDay();
    }

    /**
     * @param  list<string>  $candidateIds
     * @return list<string>
     */
    private function heldIds(int $tenantId, string $class, string $table, array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $now = CarbonImmutable::now('UTC');
        $holds = LegalHold::query()
            ->where('tenant_id', $tenantId)
            ->where('retention_class', $class)
            ->whereNull('released_at')
            ->where('starts_at', '<=', $now)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->get();

        $held = [];

        foreach ($holds as $hold) {
            if ($hold->subject_type === $table || $hold->subject_type === '*') {
                if ($hold->subject_id === '*' || in_array($hold->subject_id, $candidateIds, true)) {
                    $held = [...$held, ...($hold->subject_id === '*' ? $candidateIds : [$hold->subject_id])];
                }
            }
        }

        return array_values(array_unique($held));
    }
}
