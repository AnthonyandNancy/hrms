<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

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
