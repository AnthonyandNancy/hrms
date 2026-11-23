<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class SeedDemoCommand extends Command
{
    protected $signature = 'iam:seed-demo';

    protected $description = 'Idempotently seed synthetic demo users for the DEMO tenant.';

    public function handle(PasswordHasher $passwords, Clock $clock): int
    {
        $password = env((string) config('iam.demo_password_env', 'DEMO_SEED_PASSWORD'));

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('DEMO_SEED_PASSWORD must be set to at least 12 characters. No hard-coded production password exists.');

            return self::FAILURE;
        }

        $hash = $passwords->hash($password);
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
                ],
            );

            foreach ($roles as $roleCode) {
                $grants = RoleCapability::query()->where('role_code', $roleCode)->get();
                $version = (int) $user->auth_version;

                foreach ($grants as $grant) {
                    AuthorityBinding::query()->firstOrCreate([
                        'principal_id' => (int) $user->id,
                        'capability_code' => (string) $grant->capability_code,
                        'anchor_type' => (string) $grant->anchor_type,
                        'anchor_key' => 0,
                        'valid_from' => $clock->now()->startOfDay(),
                    ], [
                        'tenant_id' => 1,
                        'anchor_id' => null,
                        'field_profile' => (string) $grant->field_profile,
                        'purpose_code' => 'DEMO_'.$roleCode,
                        'valid_to' => $clock->now()->addYear()->startOfDay(),
                        'source_type' => 'DEMO',
                        'source_id' => null,
                        'auth_version' => $version,
                        'created_at' => $clock->now(),
                    ]);
                }
            }
        }

        $this->info('Demo users are idempotent and contain synthetic data only.');

        return self::SUCCESS;
    }
}
