<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only employment-contract version history. Lifecycle owns the table;
 * People appends through the EmploymentContractVersionLedger contract only.
 *
 * @property int $id
 * @property int $employment_id
 * @property int|null $contract_id
 * @property string|null $change_type
 * @property int|null $agreed_salary_minor
 * @property string|null $agreed_currency
 * @property string|null $terms_hash
 * @property string|null $status
 * @property CarbonImmutable|null $activated_at
 * @property int|null $activated_by
 * @property string|null $reason
 * @property array<string, mixed>|null $snapshot_json
 * @property CarbonImmutable|null $recorded_at
 */
final class EmploymentContractVersion extends Model
{
    public const CHANGE_TYPES = ['CREATION', 'ACTIVATION', 'AMENDMENT', 'TERMINATION', 'RENEWAL'];

    protected $table = 'employment_contract_versions';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'contract_id',
        'change_type',
        'terms_version',
        'document_version',
        'file_object_id',
        'effective_from',
        'effective_to',
        'agreed_salary_minor',
        'agreed_currency',
        'terms_hash',
        'status',
        'activated_at',
        'activated_by',
        'expires_at',
        'reason',
        'snapshot_json',
        'recorded_at',
        'source_type',
        'source_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_id' => 'integer',
            'contract_id' => 'integer',
            'terms_version' => 'integer',
            'document_version' => 'integer',
            'file_object_id' => 'integer',
            'agreed_salary_minor' => 'integer',
            'activated_by' => 'integer',
            'activated_at' => 'immutable_datetime',
            'snapshot_json' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Contract versions are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Contract versions are append-only.');
        });
    }
}
