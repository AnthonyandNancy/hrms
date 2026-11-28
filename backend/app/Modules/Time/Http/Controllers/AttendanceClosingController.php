<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Time\Http\TimeIdempotentCommand;
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
        private readonly TimeIdempotentCommand $commands,
    ) {}

    public function close(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'time.attendance.manage');
        $data = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $result = $this->commands->run(
            'TIME:attendance.close',
            ['month' => (string) $data['month']],
            (string) $request->header('Idempotency-Key', ''),
            function () use ($principal, $data): array {
                $body = $this->closings->close((string) $data['month'], $principal->userId());

                return [
                    'status' => 201,
                    'body' => $body,
                    'resource_type' => 'attendance_period',
                    'resource_id' => (string) $body['closure_id'],
                ];
            },
        );

        return $this->respond($result);
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'time.attendance.reopen');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $result = $this->commands->run(
            'TIME:attendance.reopen',
            ['closure_id' => $id, 'reason' => (string) $data['reason']],
            (string) $request->header('Idempotency-Key', ''),
            function () use ($principal, $id, $data): array {
                $body = $this->closings->reopen($id, (string) $data['reason'], $principal->userId());

                return [
                    'status' => 200,
                    'body' => $body,
                    'resource_type' => 'attendance_period',
                    'resource_id' => (string) $body['closure_id'],
                ];
            },
        );

        return $this->respond($result);
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

    /**
     * @param  array{status: int, body: array<string, mixed>, replayed: bool}  $result
     */
    private function respond(array $result): JsonResponse
    {
        $response = $result['status'] === 201
            ? ApiResponse::created($result['body'])
            : ApiResponse::ok($result['body'], $result['status']);

        if ($result['replayed']) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->scope->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
