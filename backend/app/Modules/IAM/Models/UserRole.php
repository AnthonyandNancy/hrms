<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class UserRole extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'user_roles';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'role_code',
        'valid_from',
        'valid_to',
        'granted_by',
        'reason',
        'auth_version',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'granted_by' => 'integer',
            'auth_version' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
