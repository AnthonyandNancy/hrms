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
        'paid_leave_minutes', 'unpaid_leave_minutes', 'overtime_minutes',
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
