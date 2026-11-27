<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class TermsService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly OutboxRecorder $outbox,
    ) {}

    /**
     * Appending a version closes the previous open terms row one day before the
     * new effective date. Existing versions are never edited in place.
     *
     * @param  array<string, mixed>  $input
     */
    public function append(int $employmentId, array $input, ?int $actorUserId): EmploymentTerms
    {
        return DB::transaction(function () use ($employmentId, $input, $actorUserId): EmploymentTerms {
            $employment = Employment::query()
                ->whereKey($employmentId)
                ->lockForUpdate()
                ->first();

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            $effectiveFrom = BusinessDate::from((string) $input['effective_from']);

            if ($effectiveFrom->isBefore(BusinessDate::from((string) $employment->valid_from))
                || $effectiveFrom->compareTo(BusinessDate::from((string) $employment->valid_to)) >= 0) {
                throw new ValidationException(['effective_from' => ['Terms must start inside the employment validity window.']]);
            }

            $latest = EmploymentTerms::query()
                ->where('employment_id', $employmentId)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($latest !== null && $effectiveFrom->compareTo(BusinessDate::from((string) $latest->effective_from)) <= 0) {
                throw new ConflictException(
                    'New terms must take effect after the current terms version.',
                    ['reason' => 'TERMS_EFFECTIVE_DATE_NOT_MONOTONIC'],
                );
            }

            if ($latest !== null) {
                $latest->effective_to = $effectiveFrom->value();
                $latest->save();
            }

            $effectiveTo = (string) ($input['effective_to'] ?? BusinessDate::OPEN_END);

            if (BusinessDate::from($effectiveTo)->isAfter(BusinessDate::from((string) $employment->valid_to))) {
                $effectiveTo = (string) $employment->valid_to;
            }

            $version = $latest !== null ? (int) $latest->version + 1 : 1;
            $terms = EmploymentTerms::query()->create([
                'employment_id' => $employmentId,
                'version' => $version,
                'effective_from' => $effectiveFrom->value(),
                'effective_to' => $effectiveTo,
                'probation_end_date' => $input['probation_end_date'] ?? null,
                'work_location' => (string) $input['work_location'],
                'work_schedule' => (string) $input['work_schedule'],
                'salary_scheme_code' => $input['salary_scheme_code'] ?? null,
                'notice_period_days' => (int) ($input['notice_period_days'] ?? 0),
                'confidentiality' => (bool) ($input['confidentiality'] ?? false),
                'non_compete' => (bool) ($input['non_compete'] ?? false),
                'signed_at' => $input['signed_at'] ?? null,
                'source_type' => 'API',
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.terms.versioned',
                aggregateType: 'employment',
                aggregateId: $employmentId,
                after: [
                    'terms_id' => (int) $terms->id,
                    'version' => $version,
                    'effective_from' => $effectiveFrom->value(),
                ],
            );

            $this->outbox->record(
                eventType: 'employment.terms.changed.v1',
                aggregateType: 'employment',
                aggregateId: (string) $employmentId,
                aggregateVersion: $version,
                payload: [
                    'employment_id' => $employmentId,
                    'terms_id' => (int) $terms->id,
                    'version' => $version,
                    'effective_from' => $effectiveFrom->value(),
                ],
            );

            return $terms;
        });
    }
}
