<?php

declare(strict_types=1);

namespace App\Modules\Talent\EffectHandlers;

use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Services\TalentEffectSupport;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\NotFoundException;

final class RequisitionApprovalEffectHandler implements DomainEffectHandler
{
    public function __construct(private readonly TalentEffectSupport $support) {}

    public function supports(string $effectType): bool
    {
        return $effectType === 'talent.requisition.approve';
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $payload = $this->support->payload($command);
            $requisitionId = (int) ($payload['requisition_id'] ?? 0);
            $requisition = Requisition::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($requisitionId)
                ->lockForUpdate()
                ->first();

            if ($requisition === null) {
                throw NotFoundException::forResource();
            }

            if ($requisition->status === 'APPROVED') {
                return DomainEffectResult::applied();
            }

            $requisition->forceFill(['status' => 'APPROVED', 'case_id' => $command->caseId])->save();
            $this->support->audit()->record(
                tenantId: $command->tenantId,
                actorUserId: null,
                action: 'talent.requisition.approved',
                aggregateType: 'recruitment_requisition',
                aggregateId: (int) $requisition->id,
                after: ['case_id' => $command->caseId, 'headcount' => (int) $requisition->headcount],
            );

            return DomainEffectResult::appliedWithOutbox('recruitment.requisition.approved.v1', [
                'requisition_id' => (int) $requisition->id,
                'case_id' => $command->caseId,
            ]);
        });
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Requisition approval cannot be automatically reversed.');
    }
}
