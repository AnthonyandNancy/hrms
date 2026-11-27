<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $person_id
 * @property string $employee_no
 * @property string $employment_type
 * @property string $status
 * @property string $valid_from
 * @property string $valid_to
 */
final class Employment extends Model
{
    public const TYPES = ['FULL_TIME', 'PART_TIME', 'FIXED_TERM', 'INTERN', 'CONTRACTOR'];

    public const STATUSES = ['PROBATION', 'ACTIVE', 'LEAVE', 'SUSPENDED', 'TERMINATED'];

    protected $table = 'employments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
        'employee_no',
        'employment_type',
        'status',
        'valid_from',
        'valid_to',
        'hire_date',
        'termination_date',
        'seniority_start_date',
        'recorded_at',
        'superseded_at',
        'source_type',
        'source_id',
        'archived_at',
        'archived_by',
        'archive_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'person_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'archived_by' => 'integer',
        ];
    }
}
