<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bind a code-owned role template to a user without going through the API.
 *
 * The command is the installer's role-assignment path. It enforces the
 * wildcard time-box (SUPER_ADMIN 30 days, SYSTEM_ADMIN 366 days), bumps
 * auth_version so stale sessions die, and is idempotent on the same anchor.
 */
final class BindRoleCommand extends Command
{
    protected $signature = 'iam:bind-role
        {--user= : User id}
        {--role= : Code-owned role template, e.g. SYSTEM_ADMIN}
        {--legal-entity= : Legal entity anchor id}
        {--days= : Validity in days; defaults to the role ceiling}';

    protected $description = 'Bind a role template to a user, enforcing the wildcard time-box (idempotent)';

    public function handle(Clock $clock): int
    {
        $userId = (int) $this->option('user');
        $roleCode = strtoupper(trim((string) $this->option('role')));
        $legalEntityId = (int) $this->option('legal-entity');

        $user = User::query()->whereKey($userId)->first();
        $template = RoleTemplateCatalog::find($roleCode);

        if ($user === null || $template === null || $legalEntityId < 1) {
            $this->error('A valid --user, --role and --legal-entity are required.');

            return self::FAILURE;
        }

        $maxDays = match ($roleCode) {
            'SUPER_ADMIN' => 30,
            'SYSTEM_ADMIN' => 366,
            default => 366,
        };

        $days = $this->option('days') !== null ? (int) $this->option('days') : $maxDays;

        if ($days < 1 || $days > $maxDays) {
            $this->error("{$roleCode} may be bound for at most {$maxDays} days.");

            return self::FAILURE;
        }

        $now = $clock->now();
        $from = $now;
        $to = $now->addDays($days);

        DB::transaction(function () use ($user, $template, $legalEntityId, $from, $to, $clock): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $newVersion = (int) $locked->auth_version + 1;

            UserRole::query()->firstOrCreate([
                'tenant_id' => (int) $locked->tenant_id,
                'user_id' => (int) $locked->id,
                'role_code' => $template->code,
                'valid_from' => $from,
            ], [
                'valid_to' => $to,
                'granted_by' => null,
                'reason' => 'CLI iam:bind-role',
                'auth_version' => $newVersion,
                'created_at' => $clock->now(),
            ]);

            foreach ($template->grants as $grant) {
                $anchorId = $grant->anchorType->requiresAnchorId() ? $legalEntityId : null;

                AuthorityBinding::query()->firstOrCreate([
                    'tenant_id' => (int) $locked->tenant_id,
                    'principal_id' => (int) $locked->id,
                    'capability_code' => (string) $grant->capabilityCode,
                    'anchor_type' => (string) $grant->anchorType->value,
                    'anchor_key' => $anchorId ?? 0,
                    'valid_from' => $from,
                ], [
                    'anchor_id' => $anchorId,
                    'field_profile' => (string) $grant->fieldProfile->value,
                    'purpose_code' => 'CLI_'.$template->code,
                    'valid_to' => $to,
                    'source_type' => 'CLI',
                    'source_id' => null,
                    'auth_version' => $newVersion,
                    'created_at' => $clock->now(),
                ]);
            }

            $locked->forceFill(['auth_version' => $newVersion])->save();
        });

        $this->info("Bound {$template->code} to user {$user->id} for {$days} day(s).");

        return self::SUCCESS;
    }
}
