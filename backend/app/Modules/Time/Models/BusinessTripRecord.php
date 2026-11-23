<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

final class BusinessTripRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employment_id',
        'from_date',
        'to_date',
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
            'from_date' => 'immutable_date:Y-m-d',
            'to_date' => 'immutable_date:Y-m-d',
        ];
    }
}
