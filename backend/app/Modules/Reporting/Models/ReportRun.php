<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportRun extends Model
{
    protected $table = 'report_runs';

    /** @var list<string> */
    protected $fillable = [
        'definition_code',
        'as_of',
        'filters_json',
        'status',
        'row_count',
        'requested_by',
        'started_at',
        'completed_at',
        'failure_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date:Y-m-d',
            'filters_json' => 'array',
            'row_count' => 'integer',
            'requested_by' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
