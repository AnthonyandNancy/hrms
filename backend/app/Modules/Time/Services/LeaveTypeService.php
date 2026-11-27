<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Leave identity writer. `leave_types` stores identity/display facts only;
 * entitlement and effective-dated rules are owned by LeavePolicyService.
 */
final class LeaveTypeService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, int $actorUserId): array
    {
        return DB::transaction(function () use ($input, $actorUserId): array {
            $code = strtoupper(trim((string) $input['code']));

            if (LeaveType::query()->where('code', $code)->exists()) {
                throw new ConflictException('A leave type with this code already exists.', [
                    'reason' => 'LEAVE_TYPE_CODE_CONFLICT',
                    'code' => $code,
                ]);
            }

            $type = LeaveType::query()->create([
                'code' => $code,
                'name' => trim((string) $input['name']),
                'unit' => strtoupper((string) $input['unit']),
                'is_statutory' => (bool) ($input['is_statutory'] ?? false),
                'is_annual' => (bool) ($input['is_annual'] ?? false),
                'requires_attachment' => (bool) ($input['requires_attachment'] ?? false),
                'allow_negative' => (bool) ($input['allow_negative'] ?? false),
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                $actorUserId,
                'time.leave.type.created',
                'leave_type',
                (int) $type->id,
                after: $this->row($type),
            );

            return $this->row($type);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, int $actorUserId): array
    {
        return DB::transaction(function () use ($id, $input, $actorUserId): array {
            /** @var LeaveType|null $type */
            $type = LeaveType::query()->lockForUpdate()->whereKey($id)->first();

            if ($type === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (array_key_exists('code', $input) && strtoupper((string) $input['code']) !== strtoupper((string) $type->code)) {
                throw new ValidationException(['code' => ['The leave type code is immutable.']]);
            }

            if (array_key_exists('unit', $input) && strtoupper((string) $input['unit']) !== strtoupper((string) $type->unit)) {
                throw new ValidationException(['unit' => ['The leave unit is immutable after creation.']]);
            }

            if (array_key_exists('status', $input)
                && strtoupper((string) $input['status']) === 'INACTIVE'
                && (string) ($type->status ?? 'ACTIVE') !== 'INACTIVE') {
                $this->assertNotReferenced($type);
            }

            $before = $this->row($type);

            $type->fill([
                'name' => array_key_exists('name', $input) ? trim((string) $input['name']) : $type->name,
                'is_statutory' => array_key_exists('is_statutory', $input) ? (bool) $input['is_statutory'] : $type->is_statutory,
                'is_annual' => array_key_exists('is_annual', $input) ? (bool) $input['is_annual'] : $type->is_annual,
                'requires_attachment' => array_key_exists('requires_attachment', $input)
                    ? (bool) $input['requires_attachment']
                    : $type->requires_attachment,
                'allow_negative' => array_key_exists('allow_negative', $input)
                    ? (bool) $input['allow_negative']
                    : $type->allow_negative,
                'status' => array_key_exists('status', $input)
                    ? strtoupper((string) $input['status'])
                    : ($type->status ?? 'ACTIVE'),
            ]);
            $type->setAttribute('version', (int) $type->version + 1);
            $type->setAttribute('updated_at', $this->clock->now());
            $type->save();

            $after = $this->row($type->refresh());

            $this->audit->record(
                $actorUserId,
                'time.leave.type.updated',
                'leave_type',
                (int) $type->id,
                before: $before,
                after: $after,
            );

            return $after;
        });
    }

    private function assertNotReferenced(LeaveType $type): void
    {
        $references = [
            'leave_policy_versions' => LeavePolicyVersion::query()->where('leave_type_id', $type->id)->exists(),
            'leave_accounts' => LeaveAccount::query()->where('leave_type_id', $type->id)->exists(),
            'leave_ledger_entries' => LeaveLedgerEntry::query()->where('leave_type_id', $type->id)->exists(),
        ];

        $inUse = array_keys(array_filter($references));

        if ($inUse !== []) {
            throw new ConflictException(
                'The leave type has policy, account or ledger history and cannot be disabled.',
                ['reason' => 'LEAVE_TYPE_IN_USE', 'references' => $inUse],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function row(LeaveType $type): array
    {
        return [
            'id' => (int) $type->id,
            'code' => (string) $type->code,
            'name' => (string) $type->name,
            'unit' => (string) $type->unit,
            'is_statutory' => (bool) $type->is_statutory,
            'is_annual' => (bool) $type->is_annual,
            'requires_attachment' => (bool) $type->requires_attachment,
            'allow_negative' => (bool) $type->allow_negative,
            'status' => (string) ($type->status ?? 'ACTIVE'),
            'version' => (int) $type->version,
        ];
    }
}
