<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\PositionService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PositionController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly PositionService $positions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.read');

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'q' => ['nullable', 'string', 'max:160'],
        ]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();

        $query = Position::query()
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderBy('code');

        if (isset($data['q']) && $data['q'] !== '') {
            $query->where(function ($builder) use ($data): void {
                $builder->where('code', 'like', '%'.$data['q'].'%')
                    ->orWhere('title', 'like', '%'.$data['q'].'%');
            });
        }

        $items = $this->positions->payloads($query->get()->all(), $on);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.manage');

        $data = $request->validate([
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'grade' => ['nullable', 'string', 'max:32'],
            'headcount_limit' => ['nullable', 'integer', 'min:0'],
            'valid_from' => ['nullable', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        $position = $this->positions->open($data, $principal->userId());

        return ApiResponse::created($this->positions->payloads(
            [$position],
            $this->businessDates->today()->value(),
        )[0]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.manage');

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:32'],
            'headcount_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,DISABLED'],
        ]);

        $position = $this->positions->revise($id, $data, $principal->userId());

        return ApiResponse::ok($this->positions->payloads(
            [$position],
            $this->businessDates->today()->value(),
        )[0]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.read');

        $position = Position::query()
            ->whereKey($id)
            ->first();

        if ($position === null) {
            throw new NotFoundOrForbiddenException;
        }

        return ApiResponse::ok($this->positions->payloads(
            [$position],
            $this->businessDates->today()->value(),
        )[0]);
    }

    private function assertPermits(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
