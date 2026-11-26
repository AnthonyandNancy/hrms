<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Authorization\PermissionProjection;
use App\Modules\IAM\Models\User;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Illuminate\Http\JsonResponse;

final class PermissionPreviewController
{
    public function __construct(private readonly PermissionProjection $projection) {}

    public function __invoke(int $id): JsonResponse
    {
        /** @var User|null $target */
        $target = User::query()->whereKey($id)->first();

        if ($target === null) {
            throw new NotFoundOrForbiddenException;
        }

        $projection = $this->projection->forUser((int) $target->id, (int) $target->auth_version);

        return ApiResponse::ok([
            'user' => ['id' => (int) $target->id, 'username' => (string) $target->username],
            ...$projection,
        ]);
    }
}
