<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\LeaveReportService;
use App\Shared\ApiResponse;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only leave summary. Scope is applied from the authenticated TimeActor;
 * SELF can only ever receive their own rows.
 */
final class LeaveReportController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeaveReportService $reports,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'from' => ['required', new BusinessDateRule],
            'to' => ['required', new BusinessDateRule],
            'leave_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'org_unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'as_of' => ['sometimes', 'nullable', new BusinessDateRule],
        ]);

        $asOf = $this->scope->resolveDate(isset($data['as_of']) ? (string) $data['as_of'] : null);

        return ApiResponse::ok($this->reports->summary($actor, $data, $asOf));
    }
}
