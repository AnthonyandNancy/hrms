<?php

declare(strict_types=1);

namespace App\Modules\People\Queries;

use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use App\Shared\Authorization\Authorizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the employee list. Row scope is applied with
 * Authorizer::constrain() before pagination so totals cannot leak out-of-scope
 * rows. Assignment and manager projections use the same business date.
 */
final class AsOfEmployeeQuery
{
    /**
     * @param  array{org_unit_id?: int|null, q?: string|null, include_archived?: bool|null}  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function paginate(
        \App\Modules\IAM\Contracts\Principal $principal,
        Authorizer $authorizer,
        string $capability,
        string $on,
        array $filters,
        int $page,
        int $pageSize,
    ): array {
        $query = Employment::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereExists(function ($sub) use ($on): void {
                $sub->selectRaw('1')
                    ->from('assignments')
                    ->whereColumn('assignments.employment_id', 'employments.id')
                    ->whereColumn('assignments.tenant_id', 'employments.tenant_id')
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
                    ->whereColumn('assignments.tenant_id', 'employments.tenant_id')
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
                            ->whereColumn('persons.tenant_id', 'employments.tenant_id')
                            ->where('persons.legal_name', 'like', $term);
                    });
            });
        }

        $query = $authorizer->constrain($query, $principal, $capability, $on);
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
     * @return array<string, mixed>
     */
    public function detail(Employment $employment, string $on): array
    {
        $person = Person::query()->where('tenant_id', (int) $employment->tenant_id)->whereKey((int) $employment->person_id)->first();
        $terms = EmploymentTerms::query()
            ->where('tenant_id', (int) $employment->tenant_id)
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
            ->where('tenant_id', (int) $employment->tenant_id)
            ->where('employment_id', (int) $employment->id)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $managerEmploymentId = null;

        if ($assignment !== null && $assignment->manager_assignment_id !== null) {
            $managerEmploymentId = Assignment::query()
                ->where('tenant_id', (int) $employment->tenant_id)
                ->whereKey((int) $assignment->manager_assignment_id)
                ->where('valid_from', '<=', $on)
                ->where('valid_to', '>', $on)
                ->value('employment_id');
        }

        $person = Person::query()->where('tenant_id', (int) $employment->tenant_id)->whereKey((int) $employment->person_id)->first();

        return [
            'employment_id' => (int) $employment->id,
            'person_id' => (int) $employment->person_id,
            'person_name' => $person !== null ? (string) $person->legal_name : null,
            'employee_no' => (string) $employment->employee_no,
            'legal_entity_id' => (int) $employment->legal_entity_id,
            'status' => (string) $employment->status,
            'employment_type' => (string) $employment->employment_type,
            'valid_from' => (string) $employment->valid_from,
            'valid_to' => (string) $employment->valid_to,
            'archived_at' => $employment->archived_at instanceof \Carbon\CarbonInterface ? $employment->archived_at->toIso8601ZuluString() : null,
            'archived_by' => $employment->archived_by !== null ? (int) $employment->archived_by : null,
            'archive_reason' => $employment->archive_reason !== null ? (string) $employment->archive_reason : null,
            'current_assignment' => $assignment !== null ? [
                'id' => (int) $assignment->id,
                'org_unit_id' => (int) $assignment->org_unit_id,
                'position_id' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
                'assignment_type' => (string) $assignment->assignment_type,
                'is_primary' => (bool) $assignment->is_primary,
                'valid_from' => (string) $assignment->valid_from,
                'valid_to' => (string) $assignment->valid_to,
            ] : null,
            'manager_employment_id' => $managerEmploymentId !== null ? (int) $managerEmploymentId : null,
        ];
    }
}
