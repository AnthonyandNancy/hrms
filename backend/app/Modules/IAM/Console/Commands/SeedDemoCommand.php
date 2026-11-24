<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class SeedDemoCommand extends Command
{
    protected $signature = 'iam:seed-demo';

    protected $description = 'Idempotently seed synthetic demo users for the DEMO tenant.';

    public function handle(PasswordHasher $passwords, Clock $clock): int
    {
        $password = config('iam.demo_seed_password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('DEMO_SEED_PASSWORD must be set to at least 12 characters. No hard-coded production password exists.');

            return self::FAILURE;
        }

        $hash = $passwords->hash($password);

        // Anchor role grants to the seeded legal entity so LEGAL_ENTITY
        // capabilities resolve against a real row. When the demo organization
        // has not been seeded, grants stay null and remain fail-closed.
        // iam:seed-demo may run before people:seed-demo. Provisioning the
        // synthetic organization here keeps role anchors valid in either
        // order; the seeder is idempotent.
        $entityId = app(DemoOrganizationSeeder::class)->seedDemo()['legal_entity_id'];

        $users = [
            ['demo.admin', true, ['SYSTEM_ADMIN']],
            ['demo.hr', false, ['HR_ADMIN']],
            ['demo.manager', false, ['LINE_MANAGER']],
            ['demo.employee', false, ['EMPLOYEE']],
        ];

        foreach ($users as [$username, $privileged, $roles]) {
            $user = User::query()->firstOrCreate(
                ['tenant_id' => 1, 'username' => $username],
                [
                    'email' => $username.'@example.test',
                    'password_hash' => $hash,
                    'status' => User::STATUS_ACTIVE,
                    'is_privileged' => $privileged,
                    'mfa_enrolled_at' => $privileged ? $clock->now() : null,
                    // auth_version has no database default. Leaving it unset
                    // produced 0 on MySQL, while the bindings created below
                    // carried 1, so every authorization check failed closed and
                    // all protected endpoints returned 403.
                    'auth_version' => 1,
                ],
            );

            // Repair users created before auth_version was initialised: a 0
            // here can never match any binding and silently denies everything.
            if ((int) $user->auth_version < 1) {
                $user->forceFill(['auth_version' => 1])->save();
                $user->refresh();
            }

            foreach ($roles as $roleCode) {
                $grants = RoleCapability::query()->where('role_code', $roleCode)->get();

                foreach ($grants as $grant) {
                    $isEntityAnchor = (string) $grant->anchor_type === 'LEGAL_ENTITY';
                    $anchorId = $isEntityAnchor ? $entityId : null;
                    $anchorKey = $isEntityAnchor ? (int) $entityId : 0;

                    AuthorityBinding::query()->firstOrCreate([
                        'principal_id' => (int) $user->id,
                        'capability_code' => (string) $grant->capability_code,
                        'anchor_type' => (string) $grant->anchor_type,
                        'anchor_key' => $anchorKey,
                        'valid_from' => $clock->now()->startOfDay(),
                    ], [
                        'tenant_id' => 1,
                        'anchor_id' => $anchorId,
                        'field_profile' => (string) $grant->field_profile,
                        'purpose_code' => 'DEMO_'.$roleCode,
                        'valid_to' => $clock->now()->addYear()->startOfDay(),
                        'source_type' => 'DEMO',
                        'source_id' => null,
                        'auth_version' => (int) $user->auth_version,
                        'created_at' => $clock->now(),
                    ]);

                    // Repair stale DEMO rows created before anchors/versions
                    // were propagated, so re-seeding is idempotent and correct.
                    AuthorityBinding::query()
                        ->where('principal_id', (int) $user->id)
                        ->where('capability_code', (string) $grant->capability_code)
                        ->where('source_type', 'DEMO')
                        ->update([
                            'anchor_id' => $anchorId,
                            'anchor_key' => $anchorKey,
                            'auth_version' => (int) $user->auth_version,
                        ]);
                }
            }
        }

        $this->info('Demo users are idempotent and contain synthetic data only.');

        return self::SUCCESS;
    }
}
