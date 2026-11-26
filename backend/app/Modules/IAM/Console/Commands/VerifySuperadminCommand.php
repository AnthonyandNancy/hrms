<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

/**
 * Executable proof that the deployment baseline is complete: every catalogue
 * page, every catalogued capability and at least one effective role grant.
 * The page and capability catalogues are code-owned, so this check cannot be
 * satisfied by hand-written data that drifts from the source.
 */
final class VerifySuperadminCommand extends Command
{
    protected $signature = 'iam:verify-superadmin
        {--username= : Administrator username}
        {--role=SYSTEM_ADMIN : Code-owned role whose page template must be complete}';

    protected $description = 'Fail unless an administrator has all pages, all capabilities, a wildcard COMPANY binding and an effective role';

    public function handle(CapabilityCatalog $capabilities, PermissionResourceCatalog $pages, Clock $clock): int
    {
        $username = trim((string) $this->option('username'));
        $roleCode = strtoupper(trim((string) $this->option('role')));

        $user = User::query()->where('username', mb_strtolower($username))->first();

        if ($username === '' || $user === null) {
            $this->error("Unknown administrator '{$username}'.");

            return self::FAILURE;
        }

        $now = $clock->now();
        $expectedPages = count($pages->pages());
        $expectedCapabilities = count($capabilities->all());

        $rolePages = (int) RolePage::query()
            ->where('role_code', $roleCode)
            ->distinct()
            ->count('page_code');

        $roles = UserRole::query()
            ->where('user_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->orderBy('role_code')
            ->pluck('role_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $wildcard = AuthorityBinding::query()
            ->where('principal_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->where('capability_code', '*')
            ->where('anchor_type', 'COMPANY')
            ->whereNull('anchor_id')
            ->where('anchor_key', 0)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->exists();

        $concrete = AuthorityBinding::query()
            ->where('principal_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->where('capability_code', '!=', '*')
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->distinct()
            ->count('capability_code');

        $capabilityCount = $wildcard ? $expectedCapabilities : $concrete;

        $this->line(sprintf(
            'user=%s role=%s pages=%d/%d capabilities=%d/%d wildcard=%s roles=%d bindings_company=%s',
            $username,
            $roleCode,
            $rolePages,
            $expectedPages,
            $capabilityCount,
            $expectedCapabilities,
            $wildcard ? 'yes' : 'no',
            count($roles),
            $wildcard ? 'yes' : 'no',
        ));

        $failures = [];

        if ($rolePages !== $expectedPages) {
            $failures[] = "page grants incomplete: {$rolePages}/{$expectedPages}";
        }
        if (! in_array($roleCode, $roles, true)) {
            $failures[] = "no effective {$roleCode} user_roles row";
        }
        if (! $wildcard) {
            $failures[] = 'no effective wildcard COMPANY binding';
        }
        if ($capabilityCount < $expectedCapabilities) {
            $failures[] = "capability coverage incomplete: {$capabilityCount}/{$expectedCapabilities}";
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info('Superadmin baseline OK: pages=all capabilities=all wildcard=yes roles>=1 company=yes');

        return self::SUCCESS;
    }
}
