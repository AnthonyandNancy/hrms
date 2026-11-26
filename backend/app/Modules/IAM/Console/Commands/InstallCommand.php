<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\MfaService;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\Organization\Contracts\OrganizationBootstrap;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * The only supported production birth path.
 *
 * It creates reference data and one administrator, and deliberately creates no
 * employees, payroll runs or workflow cases. Every step is idempotent, and a
 * failure stops the run with a non-zero exit code instead of leaving a
 * half-configured tenant that looks healthy.
 */
final class InstallCommand extends Command
{
    protected $signature = 'hrms:install
        {--company-code=ACME-CN : Stable company code used for the root unit}
        {--company-name= : Company display name}
        {--country=CN : ISO country code}
        {--timezone=Asia/Shanghai : IANA timezone}
        {--currency=CNY : ISO currency code}
        {--admin-username= : First administrator username}
        {--admin-email= : First administrator email}
        {--admin-password= : Administrator password (or use ADMIN_PASSWORD env)}
        {--annual-days= : Statutory annual entitlement in days}
        {--legal-basis= : Legal basis text for statutory leave types}
        {--holidays-file= : CSV with date,name,type columns}
        {--enable= : Comma-separated feature flags to enable}
        {--approved-by= : Approver user id for statutory payroll parameters}
        {--payroll-year= : Tax year for statutory payroll parameters}';

    protected $description = 'Create the production reference baseline and the first administrator (idempotent)';

    public function handle(
        PasswordHasher $passwords,
        Clock $clock,
        AuditRecorder $audit,
    ): int {
        $report = new InstallReport;

        $code = trim((string) $this->option('company-code'));
        $name = trim((string) $this->option('company-name'));
        $username = trim((string) $this->option('admin-username'));
        $email = trim((string) $this->option('admin-email'));
        $password = (string) ($this->option('admin-password') ?: config('iam.install.admin_password', ''));

        foreach (['--company-code' => $code, '--company-name' => $name, '--admin-username' => $username, '--admin-email' => $email] as $option => $value) {
            if ($value === '') {
                $this->error("{$option} is required.");

                return self::FAILURE;
            }
        }

        if (strlen($password) < 12) {
            // Validate before the first write so a bad invocation leaves no
            // partial tenant behind.
            $this->error('The administrator password must be at least 12 characters. Nothing was created.');

            return self::FAILURE;
        }

        // Reference data is not optional in a production birth path: an ANNUAL
        // leave type without an entitlement/legal basis is not a usable
        // baseline, and silently inventing either is a legal decision.
        foreach (['--annual-days' => (string) $this->option('annual-days'), '--legal-basis' => (string) $this->option('legal-basis')] as $option => $value) {
            if (trim($value) === '') {
                $this->error("{$option} is required for the reference baseline. Nothing was created.");

                return self::FAILURE;
            }
        }

        $tenantId = 1;
        $admin = null;

        try {
            $bootstrap = app(OrganizationBootstrap::class)->ensureCompanyWithRoot(
                $code,
                $name,
                (string) $this->option('country'),
                (string) $this->option('timezone'),
                (string) $this->option('currency'),
            );
            $report->record('company_profile', $bootstrap['company_profile_created'] ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, 'id='.$bootstrap['company_profile_id']);
            $report->record('root_org_unit', $bootstrap['root_org_unit_created'] ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, 'id='.$bootstrap['root_org_unit_id']);

            $this->runStep($report, 'capabilities', 'iam:sync-capabilities');
            $this->runStep($report, 'workflow_templates', 'workflow:seed-templates', ['--tenant-id' => $tenantId]);
            $this->runStep($report, 'lifecycle_templates', 'lifecycle:seed-templates', ['--tenant' => $tenantId]);
            $this->runStep($report, 'leave_types', 'time:seed-leave-types', array_filter([
                '--tenant' => $tenantId,
                '--annual-days' => $this->option('annual-days'),
                '--legal-basis' => $this->option('legal-basis'),
            ], static fn ($value): bool => $value !== null && $value !== ''));

            $this->runStep($report, 'calendar', 'time:seed-calendar', array_filter([
                '--timezone' => $this->option('timezone'),
                '--holidays-file' => $this->option('holidays-file'),
            ], static fn ($value): bool => $value !== null && $value !== ''));

            if ($this->option('approved-by') !== null && $this->option('payroll-year') !== null) {
                $this->runStep($report, 'payroll_parameters', 'payroll:seed-parameters', [
                    '--year' => (int) $this->option('payroll-year'),
                    '--approved-by' => (int) $this->option('approved-by'),
                ]);
            } else {
                $report->record('payroll_parameters', InstallStep::STATUS_SKIPPED, '--approved-by/--payroll-year not supplied');
                $this->warn('Statutory payroll parameters were skipped: supply --approved-by and --payroll-year.');
            }

            $admin = User::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'username' => mb_strtolower($username)],
                [
                    'email' => $email,
                    'password_hash' => $passwords->hash($password),
                    'status' => User::STATUS_ACTIVE,
                    'is_privileged' => true,
                    'auth_version' => 1,
                ],
            );
            $report->record('administrator', $admin->wasRecentlyCreated ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, "id={$admin->id}");

            $this->runStep($report, 'administrator_role', 'iam:bind-role', [
                '--user' => (int) $admin->id,
                '--role' => 'SYSTEM_ADMIN',
            ]);

            $this->runStep($report, 'superadmin_baseline', 'iam:verify-superadmin', ['--username' => $username]);

            $factors = MfaFactor::query()->where('user_id', $admin->id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->count();

            if (! (bool) config('iam.mfa.enabled', false)) {
                $report->record('administrator_mfa', InstallStep::STATUS_SKIPPED, 'MFA disabled by IAM_MFA_ENABLED=false');
            } elseif ($factors === 0) {
                $mfa = app(MfaService::class);
                $enrollment = $mfa->beginTotpEnrollment($admin);
                $recoveryCodes = $mfa->confirmTotpEnrollment(
                    $admin,
                    (int) $enrollment['factor_id'],
                    app(\App\Modules\IAM\Services\TotpService::class)->code($enrollment['secret'], $clock->now()->getTimestamp()),
                );

                $this->line('TOTP secret (printed once): '.$enrollment['secret']);
                $this->line('otpauth URI: '.$enrollment['otpauth_uri']);
                $this->line('Recovery codes (printed once): '.implode(', ', $recoveryCodes));
                $report->record('administrator_mfa', InstallStep::STATUS_CREATED, 'TOTP enrolled');
            } else {
                $report->record('administrator_mfa', InstallStep::STATUS_SKIPPED, "factors={$factors}");
            }

            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('enable')))) as $flag) {
                $this->runStep($report, "feature:{$flag}", 'feature:set', [$flag, 'on', '--tenant' => $tenantId, '--reason' => 'installer baseline', '--actor' => (int) $admin->id]);
            }

            $audit->record(
                tenantId: $tenantId,
                actorUserId: (int) $admin->id,
                action: 'install.completed',
                aggregateType: 'tenant',
                aggregateId: (string) $tenantId,
                metadata: ['steps' => array_map(static fn (InstallStep $step): array => ['name' => $step->name, 'status' => $step->status, 'detail' => $step->detail], $report->steps())],
            );
        } catch (\Throwable $exception) {
            $this->error('Install failed: '.$exception->getMessage());
            $this->printReport($report);
            $this->error('Install did not complete. Existing rows are preserved; fix the cause and rerun.');

            return self::FAILURE;
        }

        $this->printReport($report);

        if ((bool) config('iam.mfa.enabled', false)) {
            $this->info('Install complete. Sign in as '.$username.' and enrol the printed TOTP secret.');
        } else {
            $this->info('Install complete. Sign in as '.$username.' with the password you supplied (MFA disabled).');
        }

        return self::SUCCESS;
    }

    private function printReport(InstallReport $report): void
    {
        $this->table(['Step', 'Status', 'Detail'], array_map(
            static fn (InstallStep $step): array => [$step->name, $step->status, $step->detail],
            $report->steps(),
        ));
    }

    /**
     * @param  array<string|int, mixed>  $arguments
     */
    private function runStep(InstallReport $report, string $name, string $command, array $arguments = []): void
    {
        $exit = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());

        $report->record($name, $exit === 0 ? InstallStep::STATUS_CREATED : InstallStep::STATUS_FAILED, $output);

        if ($exit !== 0) {
            throw new \RuntimeException("{$command} failed: {$output}");
        }
    }
}
