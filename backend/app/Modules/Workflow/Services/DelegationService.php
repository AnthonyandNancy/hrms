<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Time-bounded, scoped proxy approval. Amount and scope are checked against
 * the frozen case payload; a delegation can never exceed its grant.
 */
final class DelegationService
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  array<string, mixed>  $scope
     */
    public function create(
        WorkflowPrincipal $principal,
        int $proxyUserId,
        string $validFrom,
        string $validTo,
        ?int $maxAmountMinor,
        array $scope,
    ): WorkflowDelegation {
        if ($proxyUserId === $principal->userId) {
            throw new ValidationException(['proxy_user_id' => ['A user cannot delegate to themselves.']], 'Invalid delegation.');
        }

        $this->assertDate($validFrom);
        $this->assertDate($validTo);

        if ($validTo < $validFrom) {
            throw new ValidationException(['valid_to' => ['The delegation end date must not precede its start date.']], 'Invalid delegation.');
        }

        if ($maxAmountMinor !== null && $maxAmountMinor < 0) {
            throw new ValidationException(['max_amount_minor' => ['The amount limit cannot be negative.']], 'Invalid delegation.');
        }

        return WorkflowDelegation::query()->create([
            'tenant_id' => $principal->tenantId,
            'proxy_user_id' => $proxyUserId,
            'principal_user_id' => $principal->userId,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'max_amount_minor' => $maxAmountMinor,
            'scope_json' => $scope,
            'active' => true,
            'revoked_at' => null,
            'created_by' => $principal->userId,
        ]);
    }

    public function revoke(WorkflowPrincipal $principal, int $delegationId): WorkflowDelegation
    {
        return DB::transaction(function () use ($principal, $delegationId): WorkflowDelegation {
            /** @var WorkflowDelegation|null $delegation */
            $delegation = WorkflowDelegation::query()
                ->where('tenant_id', $principal->tenantId)
                ->whereKey($delegationId)
                ->lockForUpdate()
                ->first();

            if ($delegation === null) {
                throw \App\Shared\Exceptions\NotFoundException::forResource();
            }

            if ($delegation->principal_user_id !== $principal->userId
                && $delegation->proxy_user_id !== $principal->userId
                && ! $principal->can('workflow.case.admin')) {
                throw \App\Shared\Exceptions\NotFoundException::forResource();
            }

            $delegation->forceFill(['active' => false, 'revoked_at' => $this->clock->now()])->save();

            return $delegation->fresh() ?? $delegation;
        });
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function grantFor(
        WorkflowPrincipal $principal,
        CaseModel $case,
        DecisionAction $action,
        array $node,
    ): ?ActingGrant {
        $date = $this->clock->now()->toDateString();
        $amount = $this->amountMinor($case->payload_json);

        $delegations = WorkflowDelegation::query()
            ->where('tenant_id', $principal->tenantId)
            ->where('proxy_user_id', $principal->userId)
            ->where('active', true)
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', $date)
            ->where('valid_to', '>=', $date)
            ->orderBy('id')
            ->get();

        foreach ($delegations as $delegation) {
            if (! $this->scopeAllows($delegation, $case, $action, $node)) {
                continue;
            }

            if ($delegation->max_amount_minor !== null && $amount !== null && $amount > $delegation->max_amount_minor) {
                continue;
            }

            return new ActingGrant(
                delegationId: $delegation->id,
                principalUserId: $delegation->principal_user_id,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function scopeAllows(WorkflowDelegation $delegation, CaseModel $case, DecisionAction $action, array $node): bool
    {
        $scope = (array) ($delegation->scope_json ?? []);

        if (isset($scope['case_type']) && is_string($scope['case_type']) && $scope['case_type'] !== $case->type) {
            return false;
        }

        if (isset($scope['node_key']) && is_string($scope['node_key']) && $scope['node_key'] !== (string) ($node['key'] ?? '')) {
            return false;
        }

        if (isset($scope['actions']) && is_array($scope['actions']) && ! in_array($action->value, $scope['actions'], true)) {
            return false;
        }

        if (isset($scope['case_ids']) && is_array($scope['case_ids']) && ! in_array($case->id, array_map('intval', $scope['case_ids']), true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function amountMinor(array $payload): ?int
    {
        foreach (['amount_minor', 'total_amount_minor'] as $key) {
            if (isset($payload[$key]) && is_int($payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }

    private function assertDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new ValidationException(['date' => ['A valid business date is required.']], 'Invalid date.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            throw new ValidationException(['date' => ['A valid business date is required.']], 'Invalid date.');
        }
    }
}
