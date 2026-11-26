<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Services\RolePageService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RolePageController
{
    public function __construct(private readonly RolePageService $rolePages) {}

    public function index(string $id): JsonResponse
    {
        return ApiResponse::ok($this->rolePages->show($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'pages' => ['required', 'array', 'max:160'],
            'pages.*' => ['required', 'string', 'max:120'],
        ]);

        return ApiResponse::ok($this->rolePages->replace($id, array_values((array) $data['pages'])));
    }
}
