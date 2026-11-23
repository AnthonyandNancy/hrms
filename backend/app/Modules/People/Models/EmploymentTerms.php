<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property int $version
 * @property string $effective_from
 * @property string $effective_to
 */
final class EmploymentTerms extends Model
{
    protected $table = 'employment_terms';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'version',
        'effective_from',
        'effective_to',
        'probation_end_date',
        'work_location',
        'work_schedule',
        'salary_scheme_code',
        'notice_period_days',
        'confidentiality',
        'non_compete',
        'signed_at',
        'source_type',
        'source_id',
        'recorded_at',
    ];

    /**
     * Terms versions are immutable history. The service may end the previous
     * open version by setting effective_to; all other edits are rejected.
     */
    protected static function booted(): void
    {
        self::updating(function (EmploymentTerms $terms): void {
            $allowed = ['effective_to'];
            $dirty = array_keys($terms->getDirty());
            $unexpected = array_values(array_diff($dirty, $allowed));

            if ($unexpected !== []) {
                throw new \DomainException('Employment terms versions are append-only; create a new version instead.');
            }
        });

        self::deleting(function (): never {
            throw new \DomainException('Employment terms versions are append-only; create a new version instead.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'employment_id' => 'integer',
            'version' => 'integer',
            'notice_period_days' => 'integer',
            'confidentiality' => 'boolean',
            'non_compete' => 'boolean',
            'signed_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
