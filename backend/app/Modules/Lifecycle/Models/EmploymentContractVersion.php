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
 * @property string|null $reason
 * @property array<string, mixed>|null $snapshot_json
 * @property CarbonImmutable|null $recorded_at
 */
final class EmploymentContractVersion extends Model
{
    public const CHANGE_TYPES = ['AMENDMENT', 'TERMINATION', 'RENEWAL'];

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
            'snapshot_json' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
