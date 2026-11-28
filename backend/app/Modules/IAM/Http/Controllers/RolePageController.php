<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Authorization\PageActionCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\IAM\Services\RolePageService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RolePageController
{
    public function __construct(
        private readonly RolePageService $rolePages,
        private readonly PermissionResourceCatalog $catalog,
        private readonly PageActionCatalog $actions,
    ) {}

    /**
     * Full code-owned page catalogue for the authorization matrix. This is
     * intentionally separate from /me/permissions, which only returns pages
     * granted to the caller and therefore cannot populate an editor.
     */
    public function catalog(): JsonResponse
    {
        return ApiResponse::ok([
            'pages' => $this->catalog->pages(),
            'action_definitions' => $this->actions->forPageCodes($this->catalog->pageCodes()),
            'read_only' => true,
        ]);
    }

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
