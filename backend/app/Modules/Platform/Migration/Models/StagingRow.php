<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Models;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One model covers all six identical `stg_*` tables. Callers obtain the right
 * table through forDomain(); raw and normalized JSON are retained for audit and
 * reconciliation even after apply.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $run_id
 * @property int $source_row_number
 * @property string $row_hash
 * @property array<string, string> $raw_json
 * @property array<string, mixed>|null $normalized_json
 * @property string $validation_status
 * @property int|null $target_id
 */
final class StagingRow extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    public static function forDomain(MigrationDomain $domain): self
    {
        $model = new self;
        $model->setTable(self::tableFor($domain));

        return $model;
    }

    public static function tableFor(MigrationDomain $domain): string
    {
        return match ($domain) {
            MigrationDomain::ORGANIZATIONS => 'stg_organizations',
            MigrationDomain::PEOPLE => 'stg_people',
            MigrationDomain::ASSIGNMENTS => 'stg_assignments',
            MigrationDomain::TERMS => 'stg_terms',
            MigrationDomain::LEAVE_OPENINGS => 'stg_leave_openings',
            MigrationDomain::PAYROLL_YTD => 'stg_payroll_ytd',
        };
    }

    public function getTable(): string
    {
        if ($this->table === null) {
            throw new RuntimeException('Staging table is not set. Use StagingRow::forDomain().');
        }

        return $this->table;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
            'normalized_json' => 'array',
            'source_row_number' => 'integer',
            'target_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
