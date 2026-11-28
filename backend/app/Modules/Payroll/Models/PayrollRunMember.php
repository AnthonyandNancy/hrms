<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property int $user_id
 * @property string $member_role
 * @property int $assigned_by
 * @property string $reason
 * @property CarbonImmutable $valid_from
 */
final class PayrollRunMember extends Model
{
    public const ROLE_PREPARER = 'PREPARER';

    public const ROLE_REVIEWER = 'REVIEWER';

    public const ROLE_APPROVER = 'APPROVER';

    public const ROLE_PUBLISHER = 'PUBLISHER';

    public const ROLE_DISBURSER = 'DISBURSER';

    public const ROLES = [
        self::ROLE_PREPARER,
        self::ROLE_REVIEWER,
        self::ROLE_APPROVER,
        self::ROLE_PUBLISHER,
        self::ROLE_DISBURSER,
    ];

    protected $table = 'payroll_run_members';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'user_id',
        'member_role',
        'assigned_by',
        'reason',
        'valid_from',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'user_id' => 'integer',
            'assigned_by' => 'integer',
            'valid_from' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
