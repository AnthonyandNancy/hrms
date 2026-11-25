<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
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
    public const TYPES = ['FIXED_TERM', 'OPEN_ENDED', 'INTERNSHIP', 'CONSULTANT'];

    public const STATUSES = ['DRAFT', 'ACTIVE', 'EXPIRING', 'RENEWED', 'TERMINATED', 'ARCHIVED'];

    protected $table = 'employment_contracts';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
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
        'legal_entity_id',
        'terms_summary',
        'recorded_at',
        'source_type',
        'source_id',
        'created_at',
        'updated_at',
    ];
}
