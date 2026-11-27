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

    /**
     * Stored lifecycle states plus EXPIRED, which is the effective state for a
     * past end date and is accepted by the ledger filter. Expiry is derived
     * from the company business date, never from the browser clock.
     */
    public const STATUSES = ['DRAFT', 'ACTIVE', 'EXPIRING', 'EXPIRED', 'RENEWED', 'TERMINATED', 'ARCHIVED'];

    /** States that may be amended, terminated, or used as a renewal source. */
    public const OPERATIONAL_STATUSES = ['ACTIVE', 'EXPIRING'];

    public const EXPIRY_WINDOW_DAYS = 60;

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
        'work_location',
        'probation_end_date',
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
