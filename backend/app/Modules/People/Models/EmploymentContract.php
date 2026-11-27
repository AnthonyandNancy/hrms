<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $employment_id
 * @property string $contract_no
 * @property string $status
 * @property string $end_date
 * @property string|null $terminated_on
 * @property int|null $renewed_by_id
 * @property int|null $previous_contract_id
 */
final class EmploymentContract extends Model
{
    use SoftDeletes;

    public const TYPES = ['FIXED_TERM', 'OPEN_ENDED', 'INTERNSHIP', 'CONSULTANT'];

    public const STATUSES = ['DRAFT', 'ACTIVE', 'EXPIRING', 'RENEWED', 'TERMINATED', 'ARCHIVED'];

    protected $table = 'employment_contracts';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'contract_no',
        'contract_type',
        'start_date',
        'end_date',
        'signed_on',
        'terminated_on',
        'status',
        'version',
        'previous_contract_id',
        'renewed_by_id',
        'template_id',
        'terms_summary',
        'recorded_at',
        'source_type',
        'source_id',
        'created_at',
        'updated_at',
        'deleted_by',
        'delete_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'immutable_datetime',
            'deleted_by' => 'integer',
        ];
    }
}
