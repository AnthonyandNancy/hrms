<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Effective-dated leave rule. Rules are append-only facts; retiring a version
 * never rewrites its definition or historical applications.
 *
 * @property int $id
 * @property int $leave_type_id
 * @property int $version
 * @property string $status
 * @property \Carbon\CarbonImmutable $effective_from
 * @property \Carbon\CarbonImmutable $effective_to
 * @property array<string, mixed> $applies_to_json
 * @property array<string, mixed> $accrual_json
 * @property array<string, mixed>|null $carryover_json
 * @property int $min_notice_days
 * @property int|null $max_consecutive_days
 * @property int|null $requires_attachment_over_days
 * @property bool $allow_half_day
 * @property string|null $legal_basis
 * @property \Carbon\CarbonImmutable $published_at
 * @property int $published_by
 * @property \Carbon\CarbonImmutable|null $retired_at
 */
final class LeavePolicyVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'leave_type_id',
        'version',
        'status',
        'effective_from',
        'effective_to',
        'applies_to_json',
        'accrual_json',
        'carryover_json',
        'min_notice_days',
        'max_consecutive_days',
        'requires_attachment_over_days',
        'allow_half_day',
        'legal_basis',
        'published_at',
        'published_by',
        'retired_at',
        'retire_reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'immutable_date:Y-m-d',
            'effective_to' => 'immutable_date:Y-m-d',
            'applies_to_json' => 'array',
            'accrual_json' => 'array',
            'carryover_json' => 'array',
            'min_notice_days' => 'integer',
            'max_consecutive_days' => 'integer',
            'requires_attachment_over_days' => 'integer',
            'allow_half_day' => 'boolean',
            'published_at' => 'immutable_datetime',
            'published_by' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
