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
        {--legal-entity-code= : Legal entity code, e.g. ACME-CN}
        {--legal-entity-name= : Legal entity display name}
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

        $code = trim((string) $this->option('legal-entity-code'));
        $name = trim((string) $this->option('legal-entity-name'));
        $username = trim((string) $this->option('admin-username'));
        $email = trim((string) $this->option('admin-email'));
        $password = (string) ($this->option('admin-password') ?: env('ADMIN_PASSWORD', ''));

        foreach (['--legal-entity-code' => $code, '--legal-entity-name' => $name, '--admin-username' => $username, '--admin-email' => $email] as $option => $value) {
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

        $tenantId = 1;
        $admin = null;

        try {
            $bootstrap = app(OrganizationBootstrap::class)->ensureLegalEntityWithRoot(
                $tenantId,
                $code,
                $name,
                (string) $this->option('country'),
                (string) $this->option('timezone'),
                (string) $this->option('currency'),
            );
            $report->record('legal_entity', $bootstrap['legal_entity_created'] ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, 'id='.$bootstrap['legal_entity_id']);
            $report->record('root_org_unit', $bootstrap['root_org_unit_created'] ? InstallStep::STATUS_CREATED : InstallStep::STATUS_SKIPPED, 'id='.$bootstrap['root_org_unit_id']);
            $entityId = $bootstrap['legal_entity_id'];

            $this->runStep($report, 'capabilities', 'iam:sync-capabilities');
            $this->runStep($report, 'workflow_templates', 'workflow:seed-templates', ['--tenant-id' => $tenantId]);
            $this->runStep($report, 'lifecycle_templates', 'lifecycle:seed-templates', ['--tenant' => $tenantId]);
            $this->runStep($report, 'leave_types', 'time:seed-leave-types', array_filter([
                '--tenant' => $tenantId,
                '--annual-days' => $this->option('annual-days'),
                '--legal-basis' => $this->option('legal-basis'),
            ], static fn ($value): bool => $value !== null && $value !== ''));

            $this->runStep($report, 'calendar', 'time:seed-calendar', array_filter([
                '--legal-entity' => $entityId,
                '--timezone' => $this->option('timezone'),
                '--holidays-file' => $this->option('holidays-file'),
            ], static fn ($value): bool => $value !== null && $value !== ''));

            if ($this->option('approved-by') !== null && $this->option('payroll-year') !== null) {
                $this->runStep($report, 'payroll_parameters', 'payroll:seed-parameters', [
                    '--legal-entity' => $entityId,
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
                '--legal-entity' => $entityId,
            ]);

            $factors = MfaFactor::query()->where('user_id', $admin->id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->count();

            if ($factors === 0) {
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
                metadata: ['steps' => array_map(static fn (InstallStep $step): array => ['name' => $step->name, 'status' => $step->status], $report->steps())],
            );
        } catch (\Throwable $exception) {
            $this->error('Install failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Step', 'Status', 'Detail'], array_map(
            static fn (InstallStep $step): array => [$step->name, $step->status, $step->detail],
            $report->steps(),
        ));

        $this->info('Install complete. Sign in as '.$username.' and enrol the printed TOTP secret.');

        return self::SUCCESS;
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
