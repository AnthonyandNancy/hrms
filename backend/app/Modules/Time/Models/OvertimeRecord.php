<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $business_date
 * @property int $minutes
 * @property string $status
 * @property int|null $case_id
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class OvertimeRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employment_id',
        'business_date',
        'minutes',
        'status',
        'case_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date:Y-m-d',
            'minutes' => 'integer',
        ];
    }
}
