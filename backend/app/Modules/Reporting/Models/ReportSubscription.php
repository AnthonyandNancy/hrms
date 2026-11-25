<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned report schedule. The row stores the recipient and the approved
 * definition/filter selection only; report rows are rebuilt at execution time
 * so a paused or revoked subscription can never serve a stale copy.
 */
final class ReportSubscription extends Model
{
    use SoftDeletes;

    protected $table = 'report_subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'owner_user_id',
        'recipient_user_id',
        'definition_code',
        'frequency',
        'filters_json',
        'active',
        'last_run_at',
        'next_run_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'owner_user_id' => 'integer',
            'recipient_user_id' => 'integer',
            'filters_json' => 'array',
            'active' => 'boolean',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
