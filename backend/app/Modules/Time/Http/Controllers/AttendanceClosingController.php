<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\AttendanceClosingService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceClosingController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TimeRequestScope $scope,
        private readonly AttendanceClosingService $closings,
    ) {}

    public function close(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'time.attendance.manage');
        $data = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return ApiResponse::created($this->closings->close(
            (string) $data['month'],
            $principal->userId(),
        ));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'time.attendance.reopen');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return ApiResponse::ok($this->closings->reopen(
            $id,
            (string) $data['reason'],
            $principal->userId(),
        ));
    }

    public function recalculate(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'time.attendance.manage');
        $data = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'employment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->closings->assertOpen((string) $data['month']);

        return ApiResponse::accepted([
            'accepted' => true,
            'month' => (string) $data['month'],
            'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
        ]);
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->scope->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
