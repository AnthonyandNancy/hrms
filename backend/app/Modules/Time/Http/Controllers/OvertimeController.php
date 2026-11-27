<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\OvertimeSettlementService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OvertimeController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TimeRequestScope $scope,
        private readonly OvertimeSettlementService $settlements,
    ) {}

    public function settle(Request $request, int $recordId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->scope->today()->value();

        if (! $this->authorizer->permits($principal, 'time.overtime.settle', $today)) {
            throw new AuthorizationException('Forbidden.');
        }

        $data = $request->validate([
            'method' => ['required', 'string', 'in:PAY,TIMEOFF'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return ApiResponse::created($this->settlements->settle(
            $recordId,
            (string) $data['method'],
            (string) $data['reason'],
            $principal->userId(),
        ));
    }
}
