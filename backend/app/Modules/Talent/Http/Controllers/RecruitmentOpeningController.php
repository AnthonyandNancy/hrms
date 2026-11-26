<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Talent\Services\RequisitionOpeningService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecruitmentOpeningController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly RequisitionOpeningService $openings,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'recruitment.requisition.read');
        $items = $this->openings->list($id);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'recruitment.requisition.manage');
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:INTERNAL,EXTERNAL,REFERRAL,AGENCY'],
        ]);

        return ApiResponse::created($this->openings->open(
            $id,
            (string) $data['channel'],
            $principal->userId(),
        ));
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->allows($principal, $capability, AnchorRef::company(), $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
