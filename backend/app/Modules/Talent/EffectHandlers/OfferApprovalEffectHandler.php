<?php

declare(strict_types=1);

namespace App\Modules\Talent\EffectHandlers;

use App\Modules\Talent\Domain\OfferStateMachine;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Services\TalentEffectSupport;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\NotFoundException;

final class OfferApprovalEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly TalentEffectSupport $support,
        private readonly OfferStateMachine $machine,
    ) {}

    public function supports(string $effectType): bool
    {
        return $effectType === 'talent.offer.approve';
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $payload = $this->support->payload($command);
            $offerId = (int) ($payload['offer_id'] ?? 0);
            $offer = Offer::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($offerId)
                ->lockForUpdate()
                ->first();

            if ($offer === null) {
                throw NotFoundException::forResource();
            }

            if ($offer->status === 'APPROVED') {
                return DomainEffectResult::applied();
            }

            if (! $this->machine->canTransition((string) $offer->status, 'APPROVED')) {
                throw new \App\Shared\Exceptions\ConflictException('Offer cannot be approved from its current status.', [
                    'reason' => 'INVALID_OFFER_TRANSITION',
                    'status' => (string) $offer->status,
                ]);
            }

            $offer->forceFill(['status' => 'APPROVED', 'case_id' => $command->caseId])->save();
            $this->support->audit()->record(
                tenantId: $command->tenantId,
                actorUserId: null,
                action: 'talent.offer.approved',
                aggregateType: 'offer',
                aggregateId: (int) $offer->id,
                after: ['case_id' => $command->caseId, 'version' => (int) $offer->version],
            );

            return DomainEffectResult::appliedWithOutbox('recruitment.offer.approved.v1', [
                'offer_id' => (int) $offer->id,
                'candidate_id' => (int) $offer->candidate_id,
                'case_id' => $command->caseId,
            ]);
        });
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Offer approval cannot be automatically reversed.');
    }
}
