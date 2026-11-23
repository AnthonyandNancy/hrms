<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Services\NotificationService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $items = $this->notifications->listForUser($principal->tenantId(), $principal->userId());

        return ApiResponse::ok(array_values($items));
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->notifications->markRead($principal->tenantId(), $principal->userId(), $notification));
    }
}
