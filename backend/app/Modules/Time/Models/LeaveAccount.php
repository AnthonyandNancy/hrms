<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property int $leave_type_id
 * @property string $opened_on
 * @property string|null $closed_on
 */
final class LeaveAccount extends Model
{
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'leave_type_id',
        'opened_on',
        'closed_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_on' => 'immutable_date:Y-m-d',
            'closed_on' => 'immutable_date:Y-m-d',
        ];
    }
}
