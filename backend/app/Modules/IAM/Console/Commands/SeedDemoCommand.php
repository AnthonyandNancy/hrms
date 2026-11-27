<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\IAM\Services\TotpService;
use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Payroll\Contracts\PayrollRunDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

final class SeedDemoCommand extends Command
{
    protected $signature = 'iam:seed-demo';

    protected $description = 'Idempotently seed synthetic demo users for the demo company.';

    public function handle(PasswordHasher $passwords, Clock $clock): int
    {
        $password = config('iam.demo_seed_password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('DEMO_SEED_PASSWORD must be set to at least 12 characters. No hard-coded production password exists.');

            return self::FAILURE;
        }

        $hash = $passwords->hash($password);

        // COMPANY is the whole single-company instance and needs no anchor id.
        // iam:seed-demo may run before people:seed-demo, so provisioning the
        // synthetic organization here keeps the demo data coherent in either
        // order; the seeder is idempotent.
        app(DemoOrganizationSeeder::class)->seedDemo();

        $users = [
            ['demo.admin', true, ['SYSTEM_ADMIN', 'EMPLOYEE']],
            ['demo.hr', false, ['HR_ADMIN', 'EMPLOYEE']],
            ['demo.manager', false, ['LINE_MANAGER', 'EMPLOYEE']],
            ['demo.employee', false, ['EMPLOYEE']],
            ['demo.payroll', false, ['PAYROLL_SPECIALIST', 'EMPLOYEE']],
        ];

        $payrollRunId = app(PayrollRunDirectory::class)->latestRunId();
        $people = app(PeopleDirectory::class);
        $employmentWriter = app(PeopleEmploymentWriter::class);
        $demoEmployments = [
            'demo.hr' => 'E003',
            'demo.manager' => 'E001',
            'demo.employee' => 'E002',
            'demo.payroll' => 'E004',
            'demo.admin' => 'E005',
        ];

        foreach ($users as [$username, $privileged, $roles]) {
            $user = User::query()->firstOrCreate(
                ['username' => $username],
                [
                    'email' => $username.'@example.test',
                    'password_hash' => $hash,
                    'status' => User::STATUS_ACTIVE,
                    'is_privileged' => $privileged,
                    'auth_version' => 1,
                ],
            );

            // A re-seed must repair credentials too. Earlier partial runs
            // could leave an existing synthetic account on a stale password.
            $user->forceFill([
                'password_hash' => $hash,
                'status' => User::STATUS_ACTIVE,
                'is_privileged' => $privileged,
            ])->save();
            $user->refresh();

            // Repair users created before auth_version was initialised: a 0
            // here can never match any binding and silently denies everything.
            if ((int) $user->auth_version < 1) {
                $user->forceFill(['auth_version' => 1])->save();
                $user->refresh();
            }

            $employeeNo = $demoEmployments[$username] ?? null;
            $employmentId = $employeeNo !== null ? $people->employmentIdByEmployeeNo($employeeNo) : null;
            if ($employmentId !== null) {
                // Stable start date makes repeated demo seeds idempotent while
                // giving every synthetic operator a real self-service identity.
                $employmentWriter->linkUser((int) $user->id, $employmentId, '2026-01-01', null, null);
            }

            // Every demo user whose role grants P2/P3 (including self-service
            // payslip reads) now needs a real second factor at login. Enrol
            // all demo users so none is locked out by the MFA policy.
            if (! MfaFactor::query()
                ->where('user_id', (int) $user->id)
                ->whereNotNull('confirmed_at')
                ->whereNull('disabled_at')
                ->exists()) {
                $this->enrollTotp($user, $clock);
            }

            // ADR-0034 §10B: every demo/install account must own the same
            // roles in user_roles and authority_bindings. role_pages is joined
            // through user_roles, so a missing role row silently hides every
            // page while capabilities still resolve.
            foreach ($roles as $roleCode) {
                UserRole::query()->firstOrCreate(
                    [
                        'user_id' => (int) $user->id,
                        'role_code' => (string) $roleCode,
                        'auth_version' => (int) $user->auth_version,
                    ],
                    [
                        'valid_from' => $clock->now()->startOfDay(),
                        'valid_to' => $clock->now()->addYear()->startOfDay(),
                        'granted_by' => null,
                        'reason' => 'Synthetic demo seed',
                        'created_at' => $clock->now(),
                    ],
                );
            }

            foreach ($roles as $roleCode) {
                $grants = RoleCapability::query()->where('role_code', $roleCode)->get();

                foreach ($grants as $grant) {
                    $anchorType = (string) $grant->anchor_type;
                    $anchorId = null;
                    $anchorKey = 0;

                    if ($anchorType === 'PAYROLL_RUN') {
                        if ($payrollRunId === null) {
                            continue;
                        }
                        $anchorId = $payrollRunId;
                        $anchorKey = $payrollRunId;
                    }

                    $binding = AuthorityBinding::query()
                        ->where('principal_id', (int) $user->id)
                        ->where('capability_code', (string) $grant->capability_code)
                        ->where('anchor_type', $anchorType)
                        ->where('anchor_key', $anchorKey)
                        ->where('auth_version', (int) $user->auth_version)
                        ->orderByDesc('valid_from')
                        ->first();

                    $attributes = [
                        'anchor_id' => $anchorId,
                        'field_profile' => (string) $grant->field_profile,
                        'purpose_code' => 'DEMO_'.$roleCode,
                        'valid_to' => $clock->now()->addYear()->startOfDay(),
                        'source_type' => 'DEMO',
                        'source_id' => null,
                    ];

                    if ($binding === null) {
                        AuthorityBinding::query()->create([
                            'principal_id' => (int) $user->id,
                            'capability_code' => (string) $grant->capability_code,
                            'anchor_type' => $anchorType,
                            'anchor_key' => $anchorKey,
                            'valid_from' => $clock->now()->startOfDay(),
                            'auth_version' => (int) $user->auth_version,
                            'created_at' => $clock->now(),
                            ...$attributes,
                        ]);
                    } else {
                        $binding->forceFill($attributes)->save();
                    }
                }
            }
        }

        $this->info('Demo users are idempotent and contain synthetic data only.');

        return self::SUCCESS;
    }

    /**
     * Create a real confirmed TOTP factor and print the provisioning URI once
     * so the operator can actually complete a login challenge.
     */
    private function enrollTotp(User $user, Clock $clock): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();

        MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString($secret),
            'label' => 'demo-privileged',
            'confirmed_at' => $clock->now(),
        ]);

        $user->forceFill(['mfa_enrolled_at' => $clock->now()])->save();

        $issuer = (string) config('iam.mfa.totp_issuer', 'HRMS');
        $this->line("TOTP for {$user->username}: ".$totp->provisioningUri($issuer, $user->username, $secret));
    }
}
