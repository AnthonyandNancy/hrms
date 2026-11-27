<?php

declare(strict_types=1);

namespace App\Modules\People\Queries;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use App\Shared\Authorization\Authorizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the employee list and export. Row scope is applied with
 * Authorizer::constrain() before pagination/counting so totals cannot leak
 * out-of-scope rows. The same as-of predicates drive list and export.
 */
final class AsOfEmployeeQuery
{
    public function __construct(private readonly OrganizationFacts $organization) {}

    /**
     * @param  array{org_unit_id?: int|null, q?: string|null, include_archived?: bool|null}  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function paginate(
        Principal $principal,
        Authorizer $authorizer,
        string $capability,
        string $on,
        array $filters,
        int $page,
        int $pageSize,
    ): array {
        $query = $this->scopedQuery($principal, $authorizer, $capability, $on, $filters);
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('employments.id')->forPage($page, $pageSize)->get();
        $items = [];

        foreach ($rows as $employment) {
            /** @var Employment $employment */
            $items[] = $this->projection($employment, $on);
        }

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    /**
     * Exact export projection. The caller must enforce the field whitelist and
     * row cap; this method never silently truncates or fabricates a value.
     *
     * @param  array{org_unit_id?: int|null, q?: string|null, include_archived?: bool|null}  $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function exportRows(
        Principal $principal,
        Authorizer $authorizer,
        string $capability,
        string $on,
        array $filters,
        int $maxRows,
    ): array {
        $query = $this->scopedQuery($principal, $authorizer, $capability, $on, $filters);
        $total = (int) (clone $query)->count();

        if ($total > $maxRows) {
            return ['items' => [], 'total' => $total];
        }

        $items = [];
        foreach ($query->orderBy('employments.id')->get() as $employment) {
            /** @var Employment $employment */
            $row = $this->projection($employment, $on);
            $items[] = [
                'employee_no' => $row['employee_no'],
                'person_name' => $row['person_name'],
                'status' => $row['status'],
                'hire_date' => $row['hire_date'],
                'org_unit_name' => $row['org_unit_name'],
                'position_title' => $row['position_title'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param  array{org_unit_id?: int|null, q?: string|null, include_archived?: bool|null}  $filters
     * @return Builder<Employment>
     */
    private function scopedQuery(
        Principal $principal,
        Authorizer $authorizer,
        string $capability,
        string $on,
        array $filters,
    ): Builder {
        $query = Employment::query()
            ->whereExists(function ($sub) use ($on): void {
                $sub->selectRaw('1')
                    ->from('assignments')
                    ->whereColumn('assignments.employment_id', 'employments.id')
                    ->where('assignments.valid_from', '<=', $on)
                    ->where('assignments.valid_to', '>', $on);
            })
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on);

        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->whereNull('archived_at');
        }

        if (($filters['org_unit_id'] ?? null) !== null) {
            $orgUnitId = (int) $filters['org_unit_id'];
            $query->whereExists(function ($sub) use ($on, $orgUnitId): void {
                $sub->selectRaw('1')
                    ->from('assignments')
                    ->whereColumn('assignments.employment_id', 'employments.id')
                    ->where('assignments.org_unit_id', $orgUnitId)
                    ->where('assignments.valid_from', '<=', $on)
                    ->where('assignments.valid_to', '>', $on);
            });
        }

        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('employee_no', 'like', $term)
                    ->orWhereExists(function ($sub) use ($term): void {
                        $sub->selectRaw('1')
                            ->from('persons')
                            ->whereColumn('persons.id', 'employments.person_id')
                            ->where('persons.legal_name', 'like', $term);
                    });
            });
        }

        return $authorizer->constrain($query, $principal, $capability, $on);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Employment $employment, string $on): array
    {
        $person = Person::query()->whereKey((int) $employment->person_id)->first();
        $terms = EmploymentTerms::query()
            ->where('employment_id', (int) $employment->id)
            ->where('effective_from', '<=', $on)
            ->where('effective_to', '>', $on)
            ->orderByDesc('version')
            ->first();

        $projection = $this->projection($employment, $on);
        $projection['person'] = $person !== null ? [
            'id' => (int) $person->id,
            'legal_name' => (string) $person->legal_name,
            'preferred_name' => $person->preferred_name !== null ? (string) $person->preferred_name : null,
            'email' => $person->email !== null ? (string) $person->email : null,
        ] : null;
        $projection['terms'] = $terms !== null ? [
            'id' => (int) $terms->id,
            'version' => (int) $terms->version,
            'effective_from' => (string) $terms->effective_from,
            'effective_to' => (string) $terms->effective_to,
            'work_location' => (string) $terms->work_location,
            'work_schedule' => (string) $terms->work_schedule,
            'salary_scheme_code' => $terms->salary_scheme_code !== null ? (string) $terms->salary_scheme_code : null,
            'notice_period_days' => (int) $terms->notice_period_days,
            'confidentiality' => (bool) $terms->confidentiality,
            'non_compete' => (bool) $terms->non_compete,
            'probation_end_date' => $terms->probation_end_date !== null ? (string) $terms->probation_end_date : null,
        ] : null;

        return $projection;
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Employment $employment, string $on): array
    {
        $assignment = Assignment::query()
            ->where('employment_id', (int) $employment->id)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $managerEmploymentId = null;

        if ($assignment !== null && $assignment->manager_assignment_id !== null) {
            $managerEmploymentId = Assignment::query()
                ->whereKey((int) $assignment->manager_assignment_id)
                ->where('valid_from', '<=', $on)
                ->where('valid_to', '>', $on)
                ->value('employment_id');
        }

        $person = Person::query()->whereKey((int) $employment->person_id)->first();
        $orgUnit = $assignment !== null
            ? $this->organization->orgUnit((int) $assignment->org_unit_id)
            : null;
        $position = $assignment?->position_id !== null
            ? $this->organization->position((int) $assignment->position_id)
            : null;

        return [
            'employment_id' => (int) $employment->id,
            'person_id' => (int) $employment->person_id,
            'person_name' => $person !== null ? (string) $person->legal_name : null,
            'employee_no' => (string) $employment->employee_no,
            'status' => (string) $employment->status,
            'employment_type' => (string) $employment->employment_type,
            'hire_date' => (string) $employment->hire_date,
            'valid_from' => (string) $employment->valid_from,
            'valid_to' => (string) $employment->valid_to,
            'archived_at' => $employment->archived_at instanceof \Carbon\CarbonInterface ? $employment->archived_at->toIso8601ZuluString() : null,
            'archived_by' => $employment->archived_by !== null ? (int) $employment->archived_by : null,
            'archive_reason' => $employment->archive_reason !== null ? (string) $employment->archive_reason : null,
            'org_unit_name' => $orgUnit?->name,
            'position_title' => $position?->title,
            'current_assignment' => $assignment !== null ? [
                'id' => (int) $assignment->id,
                'org_unit_id' => (int) $assignment->org_unit_id,
                'org_unit_name' => $orgUnit?->name,
                'position_id' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
                'position_title' => $position?->title,
                'assignment_type' => (string) $assignment->assignment_type,
                'is_primary' => (bool) $assignment->is_primary,
                'valid_from' => (string) $assignment->valid_from,
                'valid_to' => (string) $assignment->valid_to,
            ] : null,
            'manager_employment_id' => $managerEmploymentId !== null ? (int) $managerEmploymentId : null,
        ];
    }
}
