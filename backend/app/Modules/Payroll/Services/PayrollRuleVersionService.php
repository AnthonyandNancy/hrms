<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Domain\PayrollRuleCompiler;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class PayrollRuleVersionService
{
    private const VARIABLES = [
        'base_salary', 'base_salary_cents', 'scheduled_minutes', 'worked_minutes',
        'paid_leave_minutes', 'unpaid_leave_minutes', 'overtime_minutes', 'travel_minutes',
    ];

    public function __construct(
        private readonly PayrollRuleCompiler $compiler,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<mixed>  $dependencies
     */
    public function createDraft(
        int $actorUserId,
        int $schemeItemId,
        array $ast,
        string $stage = 'EARNING',
        array $dependencies = [],
        string $roundingMode = 'HALF_UP',
    ): PayrollRuleVersion {
        if (PayrollSchemeItem::query()->whereKey($schemeItemId)->doesntExist()) {
            throw new NotFoundOrForbiddenException;
        }
        if (! in_array($roundingMode, ['HALF_UP', 'HALF_EVEN', 'DOWN', 'UP'], true)) {
            throw new ValidationException(['rounding_mode' => ['Unsupported rounding mode.']]);
        }

        $dependencies = $this->normalizeDependencies($dependencies);
        $this->assertDependencyGraph($dependencies);
        $compiled = $this->compiler->compile($ast, self::VARIABLES);

        return DB::transaction(function () use ($actorUserId, $schemeItemId, $ast, $stage, $dependencies, $roundingMode, $compiled): PayrollRuleVersion {
            $version = ((int) PayrollRuleVersion::query()->where('scheme_item_id', $schemeItemId)->max('version')) + 1;
            $theVersion = PayrollRuleVersion::query()->create([
                'scheme_item_id' => $schemeItemId,
                'version' => max(1, $version),
                'ast_json' => $ast,
                'stage' => strtoupper($stage),
                'dependencies_json' => $dependencies === [] ? null : $dependencies,
                'rounding_mode' => $roundingMode,
                'unit' => 'CENT',
                'engine_version' => 'PAYROLL_RULE_V1',
                'hash' => $compiled->hash,
                'status' => 'DRAFT',
                'created_by' => $actorUserId,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.rule_version.drafted',
                aggregateType: 'payroll_rule_version',
                aggregateId: (int) $theVersion->id,
                after: ['scheme_item_id' => $schemeItemId, 'version' => (int) $theVersion->version, 'hash' => $compiled->hash],
            );

            return $theVersion;
        });
    }

    public function publish(int $actorUserId, int $ruleVersionId): PayrollRuleVersion
    {
        return DB::transaction(function () use ($actorUserId, $ruleVersionId): PayrollRuleVersion {
            $rule = PayrollRuleVersion::query()->whereKey($ruleVersionId)->lockForUpdate()->first();
            if ($rule === null) {
                throw new NotFoundOrForbiddenException;
            }
            if ((string) $rule->status !== 'DRAFT') {
                throw new ConflictException('Only a draft payroll rule can be published.', [
                    'reason' => 'PAYROLL_RULE_NOT_DRAFT',
                    'status' => (string) $rule->status,
                ]);
            }

            $compiled = $this->compiler->compile((array) $rule->ast_json, self::VARIABLES);
            if (! hash_equals((string) $rule->hash, $compiled->hash)) {
                throw new ConflictException('Payroll rule hash does not match its AST.', [
                    'reason' => 'PAYROLL_RULE_HASH_MISMATCH',
                ]);
            }

            $this->assertDependenciesPublished((int) $rule->id, (array) ($rule->dependencies_json ?? []));

            $rule->forceFill([
                'status' => 'PUBLISHED',
                'published_by' => $actorUserId,
                'published_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.rule_version.published',
                aggregateType: 'payroll_rule_version',
                aggregateId: (int) $rule->id,
                after: ['scheme_item_id' => (int) $rule->scheme_item_id, 'version' => (int) $rule->version, 'hash' => (string) $rule->hash],
            );

            return $rule->refresh();
        });
    }

    /**
     * Dependencies are rule-version ids. They must all exist and the existing
     * graph must be acyclic before a new edge can be added.
     *
     * @param  array<mixed>  $dependencies
     * @return list<int>
     */
    private function normalizeDependencies(array $dependencies): array
    {
        $normalized = [];
        foreach ($dependencies as $dependency) {
            if (is_string($dependency) && preg_match('/^\d+$/D', $dependency) === 1) {
                $dependency = (int) $dependency;
            }
            if (! is_int($dependency) || $dependency < 1) {
                throw new ValidationException(['dependencies' => ['Rule dependencies must be positive rule-version ids.']]);
            }
            $normalized[] = $dependency;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<int>  $dependencies
     */
    private function assertDependencyGraph(array $dependencies): void
    {
        foreach ($dependencies as $dependencyId) {
            if (PayrollRuleVersion::query()->whereKey($dependencyId)->doesntExist()) {
                throw new ConflictException('A payroll rule dependency does not exist.', [
                    'reason' => 'PAYROLL_RULE_DEPENDENCY_MISSING',
                    'dependency_id' => $dependencyId,
                ]);
            }
        }

        foreach ($dependencies as $dependencyId) {
            $this->walkDependencies($dependencyId, []);
        }
    }

    /**
     * @param  list<int>  $path
     */
    private function walkDependencies(int $ruleVersionId, array $path): void
    {
        if (in_array($ruleVersionId, $path, true)) {
            throw new ConflictException('Payroll rule dependencies contain a cycle.', [
                'reason' => 'PAYROLL_RULE_DEPENDENCY_CYCLE',
                'path' => [...$path, $ruleVersionId],
            ]);
        }

        $rule = PayrollRuleVersion::query()->whereKey($ruleVersionId)->first();
        if ($rule === null) {
            return;
        }

        foreach ((array) ($rule->dependencies_json ?? []) as $dependencyId) {
            $this->walkDependencies((int) $dependencyId, [...$path, $ruleVersionId]);
        }
    }

    /**
     * @param  array<mixed>  $dependencies
     */
    private function assertDependenciesPublished(int $ruleVersionId, array $dependencies): void
    {
        foreach ($dependencies as $dependencyId) {
            $dependency = PayrollRuleVersion::query()->whereKey((int) $dependencyId)->first();
            if ($dependency === null || (string) $dependency->status !== 'PUBLISHED') {
                throw new ConflictException('Every payroll rule dependency must be published before this rule.', [
                    'reason' => 'PAYROLL_RULE_DEPENDENCY_NOT_PUBLISHED',
                    'dependency_id' => (int) $dependencyId,
                ]);
            }
        }
    }

    private function assertNoPublishedDependents(int $ruleVersionId): void
    {
        foreach (PayrollRuleVersion::query()->where('status', 'PUBLISHED')->get() as $candidate) {
            $dependencies = array_map('intval', (array) ($candidate->dependencies_json ?? []));
            if (in_array($ruleVersionId, $dependencies, true)) {
                throw new ConflictException('A published payroll rule still depends on this version.', [
                    'reason' => 'PAYROLL_RULE_HAS_PUBLISHED_DEPENDENT',
                    'dependent_rule_version_id' => (int) $candidate->id,
                ]);
            }
        }
    }

    public function retire(int $actorUserId, int $ruleVersionId, string $reason): PayrollRuleVersion
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => ['A retirement reason of at least 5 characters is required.']]);
        }

        return DB::transaction(function () use ($actorUserId, $ruleVersionId, $reason): PayrollRuleVersion {
            $rule = PayrollRuleVersion::query()->whereKey($ruleVersionId)->lockForUpdate()->first();
            if ($rule === null) {
                throw new NotFoundOrForbiddenException;
            }
            if ((string) $rule->status !== 'PUBLISHED') {
                throw new ConflictException('Only a published payroll rule can be retired.', [
                    'reason' => 'PAYROLL_RULE_NOT_PUBLISHED',
                ]);
            }

            $this->assertNoPublishedDependents((int) $rule->id);

            $rule->forceFill([
                'status' => 'RETIRED',
                'retire_reason' => mb_substr($reason, 0, 500),
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.rule_version.retired',
                aggregateType: 'payroll_rule_version',
                aggregateId: (int) $rule->id,
                after: ['reason' => $reason],
            );

            return $rule->refresh();
        });
    }
}
