<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class DashboardSnapshot extends Model
{
    protected $table = 'dashboard_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'metric_code',
        'as_of',
        'value',
        'unit',
        'status',
        'reason',
        'source_hash',
        'scope_hash',
        'payload_json',
        'computed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date:Y-m-d',
            'value' => 'integer',
            'payload_json' => 'array',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
