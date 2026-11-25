<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\TravelRequestService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\NoActiveEmploymentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TravelController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly TravelRequestService $travel,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $employmentId = $this->selfEmploymentId($actor);
        $items = $this->travel->list($actor->tenantId, $employmentId);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'destination' => ['required', 'string', 'max:160'],
            'purpose' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return ApiResponse::created($this->travel->open(
            $actor->tenantId,
            $this->selfEmploymentId($actor),
            $data,
            $actor->userId,
        ));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return ApiResponse::ok($this->travel->cancel(
            $actor->tenantId,
            $this->selfEmploymentId($actor),
            $id,
            (string) $data['reason'],
            $actor->userId,
        ));
    }

    private function selfEmploymentId(TimeActor $actor): int
    {
        if ($actor->employmentId === null || $actor->employmentId <= 0) {
            throw new NoActiveEmploymentException('time.travel.read');
        }

        return $actor->employmentId;
    }
}
