<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Contracts\PayrollCostRow;
use App\Modules\Reporting\Models\ExportJob;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A download link is not a capability. Every download re-resolves the live
 * principal, auth version, bindings, row scope and field policy before bytes
 * leave the server.
 */
final class ExportReauthorizationTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_download_is_denied_after_field_profile_is_revoked(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        // The default payroll port is unavailable; inject one published row
        // so export creation exercises the real projection and field policy.
        $this->app->instance(PayrollCostDirectory::class, new ReauthorizationPayrollDirectory([
            new PayrollCostRow(200000, 150000, 123456, 'CNY'),
        ]));

        // Replace the fixture's P0 export ceiling with P2 so the creation
        // succeeds, then revoke it to P0 before the download attempt.
        AuthorityBinding::query()
            ->where('principal_id', (int) $ids['broad_user']->id)
            ->where('capability_code', 'reporting.export')
            ->delete();
        Cache::flush();
        $this->grant(
            $ids['broad_user'],
            'reporting.export',
            AnchorType::COMPANY,
            null,
            FieldProfile::P2_SENSITIVE,
        );

        $created = $this->postExport('PAYROLL_COST', [
            'fields' => ['gross_cents'],
        ])->assertAccepted()->json('data');

        // Replace the export grant with a P0-only grant. The job was created
        // while the P2 profile allowed gross_cents; the old link must fail now.
        AuthorityBinding::query()
            ->where('principal_id', (int) $ids['broad_user']->id)
            ->where('capability_code', 'reporting.export')
            ->delete();
        Cache::flush();
        $this->grant(
            $ids['broad_user'],
            'reporting.export',
            AnchorType::COMPANY,
            null,
            FieldProfile::P0_PUBLIC,
        );

        $this->getJson('/v1/reporting/exports/'.$created['id'].'/download?token='.$created['downloadToken'])
            ->assertForbidden();

        $this->getJson((string) $created['downloadUrl'])->assertForbidden();
        $this->assertSame(0, (int) ExportJob::query()->whereKey((int) $created['id'])->value('download_count'));
    }

    public function test_download_is_denied_after_report_read_is_revoked_even_with_valid_token(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $created = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        AuthorityBinding::query()
            ->where('principal_id', (int) $ids['broad_user']->id)
            ->where('capability_code', 'reporting.report.read')
            ->delete();
        Cache::flush();

        $this->getJson('/v1/reporting/exports/'.$created['id'].'/download?token='.$created['downloadToken'])
            ->assertForbidden();
    }

    public function test_download_is_denied_after_scope_changes(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $created = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        AuthorityBinding::query()
            ->where('principal_id', (int) $ids['broad_user']->id)
            ->where('capability_code', 'reporting.report.read')
            ->delete();
        Cache::flush();
        $this->grant(
            $ids['broad_user'],
            'reporting.report.read',
            AnchorType::ORG_UNIT,
            (int) $ids['org_a_id'],
            FieldProfile::P2_SENSITIVE,
        );

        $this->getJson('/v1/reporting/exports/'.$created['id'].'/download?token='.$created['downloadToken'])
            ->assertForbidden();
    }

    public function test_download_is_denied_after_auth_version_bump(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $created = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        $ids['broad_user']->forceFill(['auth_version' => 2])->save();
        Cache::flush();
        $this->grant(
            $ids['broad_user'],
            'reporting.report.read',
            AnchorType::COMPANY,
            null,
            FieldProfile::P2_SENSITIVE,
        );
        $this->grant(
            $ids['broad_user'],
            'reporting.export',
            AnchorType::COMPANY,
            null,
            FieldProfile::P2_SENSITIVE,
        );
        // The v2 principal genuinely holds fresh company-wide grants. The
        // download still fails because the job was bound to auth_version 1.
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            2,
        );

        $this->getJson('/v1/reporting/exports/'.$created['id'].'/download?token='.$created['downloadToken'])
            ->assertForbidden();
    }
}

final class ReauthorizationPayrollDirectory implements PayrollCostDirectory
{
    /** @param list<PayrollCostRow> $rows */
    public function __construct(private readonly array $rows) {}

    public function available(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /** @return list<PayrollCostRow> */
    public function costByEntity(string $asOf): array
    {
        return $this->rows;
    }
}
