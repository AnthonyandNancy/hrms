<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class RecoveryCode extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'recovery_codes';

    /** @var list<string> */
    protected $fillable = ['user_id', 'code_hash', 'used_at', 'created_at'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
