<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $requester_user_id
 * @property int $approver_user_id
 * @property list<string> $scope_json
 */
final class BreakGlassGrant extends Model
{
    protected $table = 'break_glass_grants';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'requester_user_id',
        'approver_user_id',
        'reason',
        'granted_at',
        'expires_at',
        'revoked_at',
        'scope_json',
        'alert_sent_at',
    ];

    /** @var list<string> */
    protected $hidden = ['reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'requester_user_id' => 'integer',
            'approver_user_id' => 'integer',
            'scope_json' => 'array',
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'alert_sent_at' => 'immutable_datetime',
        ];
    }
}
