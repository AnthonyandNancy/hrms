<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\OnboardingDraftStore;
use App\Modules\People\Models\OnboardingDraft;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of People's encrypted onboarding PII drafts.
 */
final class OnboardingDraftService implements OnboardingDraftStore
{
    public function __construct(private readonly Clock $clock) {}

    public function reserve(string $employeeNo, string $idempotencyKey, array $person, int $actorUserId): array
    {
        $employeeNo = trim($employeeNo);
        $idempotencyKey = trim($idempotencyKey);

        if ($employeeNo === '' || $idempotencyKey === '') {
            throw new ConflictException('Onboarding draft key and employee number are required.', [
                'reason' => 'ONBOARDING_DRAFT_KEY_REQUIRED',
            ]);
        }

        return DB::transaction(function () use ($employeeNo, $idempotencyKey, $person, $actorUserId): array {
            $existing = OnboardingDraft::query()
                ->where('employee_no', $employeeNo)
                ->where('status', OnboardingDraft::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $existing = OnboardingDraft::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
            }

            if ($existing !== null) {
                return $this->snapshot($existing, replayed: true);
            }

            try {
                $draft = OnboardingDraft::query()->create([
                    'employee_no' => $employeeNo,
                    'idempotency_key' => $idempotencyKey,
                    'status' => OnboardingDraft::STATUS_PENDING,
                    'payload_ciphertext' => Crypt::encryptString(json_encode(
                        $person,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    )),
                    'created_by' => $actorUserId,
                    'created_at' => $this->clock->now(),
                    'updated_at' => $this->clock->now(),
                ]);
            } catch (QueryException) {
                // Concurrent reserve: return the winner only when the
                // employee number or idempotency key identifies the same
                // pending business intent.
                $draft = OnboardingDraft::query()
                    ->where(function ($query) use ($employeeNo, $idempotencyKey): void {
                        $query->where('employee_no', $employeeNo)
                            ->orWhere('idempotency_key', $idempotencyKey);
                    })
                    ->where('status', OnboardingDraft::STATUS_PENDING)
                    ->orderBy('id')
                    ->first();

                if ($draft === null) {
                    throw new ConflictException('Onboarding draft could not be reserved.', [
                        'reason' => 'ONBOARDING_DRAFT_CONFLICT',
                    ]);
                }

                return $this->snapshot($draft, replayed: true);
            }

            return $this->snapshot($draft, replayed: false);
        });
    }

    public function attachCase(int $draftId, int $caseId): void
    {
        DB::transaction(function () use ($draftId, $caseId): void {
            /** @var OnboardingDraft $draft */
            $draft = OnboardingDraft::query()
                ->whereKey($draftId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->case_id !== null && (int) $draft->case_id !== $caseId) {
                throw new ConflictException('The onboarding draft is already attached to another case.', [
                    'reason' => 'ONBOARDING_DRAFT_CASE_MISMATCH',
                    'case_id' => (int) $draft->case_id,
                ]);
            }

            $draft->forceFill([
                'case_id' => $caseId,
                'updated_at' => $this->clock->now(),
            ])->save();
        });
    }

    public function personFor(int $draftId): array
    {
        $draft = OnboardingDraft::query()->whereKey($draftId)->first();

        if ($draft === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ((string) $draft->status !== OnboardingDraft::STATUS_PENDING
            && (string) $draft->status !== OnboardingDraft::STATUS_CONSUMED) {
            throw new ConflictException('The onboarding draft is not consumable.', [
                'reason' => 'ONBOARDING_DRAFT_NOT_CONSUMABLE',
                'status' => (string) $draft->status,
            ]);
        }

        $decoded = json_decode(
            Crypt::decryptString((string) $draft->payload_ciphertext),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new ConflictException('The onboarding draft payload is invalid.', [
                'reason' => 'ONBOARDING_DRAFT_INVALID',
            ]);
        }

        return $decoded;
    }

    public function markConsumed(int $draftId): void
    {
        DB::transaction(function () use ($draftId): void {
            /** @var OnboardingDraft $draft */
            $draft = OnboardingDraft::query()
                ->whereKey($draftId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $draft->status === OnboardingDraft::STATUS_CONSUMED) {
                return;
            }

            $draft->forceFill([
                'status' => OnboardingDraft::STATUS_CONSUMED,
                'consumed_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ])->save();
        });
    }

    /**
     * @return array{
     *     draft_id:int,
     *     employee_no:string,
     *     case_id:int|null,
     *     idempotency_key:string,
     *     status:string,
     *     replayed:bool
     * }
     */
    private function snapshot(OnboardingDraft $draft, bool $replayed): array
    {
        return [
            'draft_id' => (int) $draft->id,
            'employee_no' => (string) $draft->employee_no,
            'case_id' => $draft->case_id !== null ? (int) $draft->case_id : null,
            'idempotency_key' => (string) $draft->idempotency_key,
            'status' => (string) $draft->status,
            'replayed' => $replayed,
        ];
    }
}
