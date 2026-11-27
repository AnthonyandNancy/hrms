<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\TravelReportService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scoped travel statistics. The report is a read model over Time-owned rows;
 * department expansion goes through the Shared People contract, never through
 * a direct cross-module table query.
 */
final class TravelReportController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly TravelReportService $reports,
        private readonly PersonEmploymentDirectory $people,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $today = $this->scope->today()->value();
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $employmentScope = $this->employmentScope($actor);

        if (isset($data['employment_id'])) {
            $this->scope->employment($actor, (int) $data['employment_id'], $today);
        }

        if (isset($data['department_id'])) {
            $departmentIds = $this->people->employmentIdsForOrgUnits(
                [(int) $data['department_id']],
                $today,
            );

            if ($departmentIds === []) {
                throw new NotFoundOrForbiddenException;
            }

            $employmentScope = $employmentScope === null
                ? $departmentIds
                : array_values(array_intersect($employmentScope, $departmentIds));

            if ($employmentScope === []) {
                throw new NotFoundOrForbiddenException;
            }
        }

        $result = $this->reports->summary($employmentScope, [
            'from' => (string) $data['from'],
            'to' => (string) $data['to'],
            'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
            'department_id' => isset($data['department_id']) ? (int) $data['department_id'] : null,
        ], $today);

        return ApiResponse::ok($result->toArray());
    }

    /**
     * @return list<int>|null null means the whole company
     */
    private function employmentScope(TimeActor $actor): ?array
    {
        return match ($actor->scope) {
            'SELF' => $actor->employmentId !== null ? [$actor->employmentId] : [],
            'MANAGED' => $actor->managedEmploymentIds,
            'COMPANY', 'HR', 'ADMIN' => null,
            default => [],
        };
    }
}
