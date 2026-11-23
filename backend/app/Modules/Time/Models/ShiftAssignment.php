<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property int $shift_template_id
 */
final class ShiftAssignment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employment_id',
        'shift_template_id',
        'valid_from',
        'valid_to',
        'source',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date:Y-m-d',
            'valid_to' => 'immutable_date:Y-m-d',
        ];
    }
}
