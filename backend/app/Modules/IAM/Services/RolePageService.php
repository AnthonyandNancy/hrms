<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class RolePageService
{
    public function __construct(
        private readonly PermissionResourceCatalog $catalog,
        private readonly AuthorizationSnapshot $snapshot,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{role_code: string, pages: list<array<string, mixed>>, page_codes: list<string>}
     */
    public function show(string $roleCode): array
    {
        $role = $this->roleOrFail($roleCode);
        $pageCodes = RolePage::query()
            ->where('role_code', (string) $role->code)
            ->orderBy('page_code')
            ->pluck('page_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
        $set = array_fill_keys($pageCodes, true);

        $pages = array_values(array_filter(
            $this->catalog->pages(),
            static fn (array $page): bool => isset($set[(string) $page['page_code']]),
        ));

        return ['role_code' => (string) $role->code, 'pages' => $pages, 'page_codes' => $pageCodes];
    }

    /**
     * Replace the role's page grants and replay every affected user in the
     * same transaction. Template roles are code-owned, so only custom roles
     * may be edited through the API.
     *
     * @param  list<string>  $pageCodes
     * @return array{role_code: string, pages: list<array<string, mixed>>, page_codes: list<string>}
     */
    public function replace(string $roleCode, array $pageCodes): array
    {
        $role = $this->roleOrFail($roleCode);

        if ((bool) $role->is_template) {
            throw new \App\Shared\Exceptions\ConflictException(
                'Template roles are code-owned and cannot be edited through the API.',
                ['reason' => 'TEMPLATE_ROLE_IMMUTABLE'],
            );
        }

        $known = array_column($this->catalog->pages(), 'page_code');
        $unknown = array_values(array_diff($pageCodes, $known));

        if ($unknown !== []) {
            throw new ValidationException(
                ['pages' => ['Unknown page code(s): '.implode(', ', $unknown)]],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_PAGE_CODE', 'unknown' => $unknown],
            );
        }

        $unique = array_values(array_unique(array_map(static fn (mixed $code): string => (string) $code, $pageCodes)));

        DB::transaction(function () use ($role, $unique): void {
            RolePage::query()->where('role_code', (string) $role->code)->delete();

            foreach ($unique as $pageCode) {
                RolePage::query()->create([
                    'role_code' => (string) $role->code,
                    'page_code' => $pageCode,
                    'created_at' => $this->clock->now(),
                ]);
            }

            UserRole::query()
                ->where('role_code', (string) $role->code)
                ->orderBy('user_id')
                ->pluck('user_id')
                ->unique()
                ->each(function (mixed $userId): void {
                    $user = User::query()->whereKey((int) $userId)->first();
                    if ($user instanceof User) {
                        $this->snapshot->replay($user, 'role.pages.changed');
                    }
                });
        });

        return $this->show((string) $role->code);
    }

    private function roleOrFail(string $roleCode): Role
    {
        $role = Role::query()->whereKey(strtoupper(trim($roleCode)))->first();

        if ($role === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $role;
    }
}
