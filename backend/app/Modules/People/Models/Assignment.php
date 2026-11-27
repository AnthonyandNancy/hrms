<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employment_id
 * @property int $org_unit_id
 * @property int|null $position_id
 * @property int|null $manager_assignment_id
 * @property string $assignment_type
 * @property bool $is_primary
 * @property string $valid_from
 * @property string $valid_to
 */
final class Assignment extends Model
{
    public const TYPES = ['PRIMARY', 'SECONDMENT', 'MATRIX', 'PROJECT'];

    protected $table = 'assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employment_id',
        'org_unit_id',
        'position_id',
        'manager_assignment_id',
        'assignment_type',
        'is_primary',
        'valid_from',
        'valid_to',
        'recorded_at',
        'superseded_at',
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
            'org_unit_id' => 'integer',
            'position_id' => 'integer',
            'manager_assignment_id' => 'integer',
            'is_primary' => 'boolean',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
