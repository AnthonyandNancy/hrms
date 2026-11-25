<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\EmployeeCertificate;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Models\EmployeeContact;
use App\Modules\People\Models\EmployeeEducation;
use App\Modules\People\Models\EmployeeExperience;
use App\Modules\People\Models\EmployeeImportBatch;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Versioned People-owned profile subresources. Controllers never write models;
 * this service enforces tenant scope, append-only history and masked P2 output.
 */
final class EmployeeProfileService
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $resource, int $tenantId, int $employmentId): array
    {
        $model = $this->model($resource);
        $query = $model::query()->where('tenant_id', $tenantId);

        // Import batches are tenant-level evidence, not employment-scoped.
        // Filtering them by employment_id referenced a non-existent column and
        // produced a real 500 in the Compose route audit.
        if ($resource !== 'imports') {
            $query->where('employment_id', $employmentId);
        }

        if ($this->hasColumn($model, 'superseded_at')) {
            $query->whereNull('superseded_at');
        }

        return $query->orderByDesc('id')
            ->get()
            ->map(fn (Model $row): array => $this->payload($resource, $row))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function open(string $resource, int $tenantId, int $employmentId, array $data, int $actorUserId): array
    {
        $model = $this->model($resource);
        $normalized = $this->normalize($resource, $data);
        $normalized['tenant_id'] = $tenantId;

        if ($resource !== 'imports') {
            $normalized['employment_id'] = $employmentId;
        }

        if ($this->hasColumn($model, 'recorded_at')) {
            $normalized['recorded_at'] = $this->clock->now();
        }

        if ($resource === 'change_requests') {
            $normalized['requested_by'] = $actorUserId;
            $normalized['status'] = 'DRAFT';
        }

        if ($resource === 'imports') {
            $normalized['uploaded_by'] = $actorUserId;
            $normalized['status'] = 'VALIDATING';
            $normalized['original_name'] = (string) ($data['original_name'] ?? 'employees.xlsx');
            $normalized['total_rows'] = (int) ($data['total_rows'] ?? 0);
            $normalized['errors_json'] = $data['errors'] ?? [];
        }

        /** @var Model $row */
        $row = $model::query()->create($normalized);

        return $this->payload($resource, $row);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function revise(string $resource, int $tenantId, int $employmentId, int $id, array $data): array
    {
        $model = $this->model($resource);

        return DB::transaction(function () use ($resource, $tenantId, $employmentId, $id, $data, $model): array {
            $query = $model::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($id);

            if ($resource !== 'imports') {
                $query->where('employment_id', $employmentId);
            }

            if ($this->hasColumn($model, 'superseded_at')) {
                $query->whereNull('superseded_at');
            }

            $current = $query->lockForUpdate()->first();

            if ($current === null) {
                throw new NotFoundOrForbiddenException;
            }

            $normalized = $this->normalize($resource, $data);
            $copy = $current->replicate();
            $copy->forceFill($normalized);

            if ($this->hasColumn($model, 'recorded_at')) {
                $copy->forceFill(['recorded_at' => $this->clock->now()]);
            }

            $copy->save();

            if ($this->hasColumn($model, 'superseded_at')) {
                $current->forceFill(['superseded_at' => $this->clock->now()])->save();
            } else {
                $current->delete();
            }

            return $this->payload($resource, $copy);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function submitChangeRequest(int $tenantId, int $employmentId, int $id, int $actorUserId): array
    {
        $request = EmployeeChangeRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->whereKey($id)
            ->where('status', 'DRAFT')
            ->first();

        if ($request === null) {
            throw new NotFoundOrForbiddenException;
        }

        $request->forceFill([
            'status' => 'SUBMITTED',
            'submitted_at' => $this->clock->now(),
            'requested_by' => $actorUserId,
        ])->save();

        return $this->payload('change_requests', $request);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(string $resource, array $data): array
    {
        return match ($resource) {
            'education' => [
                'school' => (string) ($data['school'] ?? ''),
                'degree' => (string) ($data['degree'] ?? ''),
                'major' => isset($data['major']) ? (string) $data['major'] : null,
                'start_date' => (string) ($data['start_date'] ?? ''),
                'end_date' => isset($data['end_date']) ? (string) $data['end_date'] : null,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            ],
            'experiences' => [
                'company' => (string) ($data['company'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
                'start_date' => (string) ($data['start_date'] ?? ''),
                'end_date' => isset($data['end_date']) ? (string) $data['end_date'] : null,
                'leave_reason' => isset($data['leave_reason']) ? (string) $data['leave_reason'] : null,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            ],
            'contacts' => [
                'name' => (string) ($data['name'] ?? ''),
                'relationship' => (string) ($data['relationship'] ?? ''),
                'phone_ciphertext' => isset($data['phone']) ? (string) $data['phone'] : null,
                'email' => isset($data['email']) ? (string) $data['email'] : null,
                'is_emergency' => (bool) ($data['is_emergency'] ?? false),
                'is_primary' => (bool) ($data['is_primary'] ?? false),
            ],
            'certificates' => [
                'certificate_type' => (string) ($data['certificate_type'] ?? ''),
                'certificate_no' => isset($data['certificate_no']) ? (string) $data['certificate_no'] : null,
                'issuer' => isset($data['issuer']) ? (string) $data['issuer'] : null,
                'issued_on' => isset($data['issued_on']) ? (string) $data['issued_on'] : null,
                'expires_on' => isset($data['expires_on']) ? (string) $data['expires_on'] : null,
                'document_id' => isset($data['document_id']) ? (int) $data['document_id'] : null,
            ],
            'change_requests' => [
                'reason' => (string) ($data['reason'] ?? ''),
                'diff_json' => $data['diff'] ?? [],
            ],
            'imports' => [
                'errors_json' => $data['errors'] ?? [],
            ],
            default => throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']),
        };
    }

    /**
     * @return class-string<Model>
     */
    private function model(string $resource): string
    {
        return match ($resource) {
            'education' => EmployeeEducation::class,
            'experiences' => EmployeeExperience::class,
            'contacts' => EmployeeContact::class,
            'certificates' => EmployeeCertificate::class,
            'change_requests' => EmployeeChangeRequest::class,
            'imports' => EmployeeImportBatch::class,
            default => throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']),
        };
    }

    private function hasColumn(string $model, string $column): bool
    {
        return in_array($column, (new $model)->getFillable(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $resource, Model $row): array
    {
        $base = ['id' => (int) $row->getKey()];

        if ($resource !== 'imports') {
            $base['employment_id'] = (int) $row->getAttribute('employment_id');
        }

        $payload = match ($resource) {
            'education' => [
                'school' => (string) $row->getAttribute('school'),
                'degree' => (string) $row->getAttribute('degree'),
                'major' => $row->getAttribute('major'),
                'start_date' => (string) $row->getAttribute('start_date'),
                'end_date' => $row->getAttribute('end_date'),
                'notes' => $row->getAttribute('notes'),
            ],
            'experiences' => [
                'company' => (string) $row->getAttribute('company'),
                'title' => (string) $row->getAttribute('title'),
                'start_date' => (string) $row->getAttribute('start_date'),
                'end_date' => $row->getAttribute('end_date'),
                'leave_reason' => $row->getAttribute('leave_reason'),
                'notes' => $row->getAttribute('notes'),
            ],
            'contacts' => [
                'name' => (string) $row->getAttribute('name'),
                'relationship' => (string) $row->getAttribute('relationship'),
                'phone' => ['display' => null, 'masked' => true, 'profile' => FieldProfile::P2_SENSITIVE->value, 'revealable' => false, 'exportable' => false],
                'email' => $row->getAttribute('email'),
                'is_emergency' => (bool) $row->getAttribute('is_emergency'),
                'is_primary' => (bool) $row->getAttribute('is_primary'),
            ],
            'certificates' => [
                'certificate_type' => (string) $row->getAttribute('certificate_type'),
                'certificate_no' => $row->getAttribute('certificate_no'),
                'issuer' => $row->getAttribute('issuer'),
                'issued_on' => $row->getAttribute('issued_on'),
                'expires_on' => $row->getAttribute('expires_on'),
                'document_id' => $row->getAttribute('document_id'),
            ],
            'change_requests' => [
                'status' => (string) $row->getAttribute('status'),
                'reason' => (string) $row->getAttribute('reason'),
                'diff' => $row->getAttribute('diff_json') ?? [],
                'requested_by' => (int) $row->getAttribute('requested_by'),
                'case_id' => $row->getAttribute('case_id'),
                'submitted_at' => $row->getAttribute('submitted_at') !== null
                    ? CarbonImmutable::parse((string) $row->getAttribute('submitted_at'))->toIso8601ZuluString()
                    : null,
            ],
            'imports' => [
                'status' => (string) $row->getAttribute('status'),
                'original_name' => (string) $row->getAttribute('original_name'),
                'total_rows' => (int) $row->getAttribute('total_rows'),
                'valid_rows' => (int) $row->getAttribute('valid_rows'),
                'failed_rows' => (int) $row->getAttribute('failed_rows'),
                'errors' => $row->getAttribute('errors_json') ?? [],
            ],
            default => throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']),
        };

        return [...$base, ...$payload];
    }
}
