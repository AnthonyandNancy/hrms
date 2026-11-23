<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'login_attempts';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'username', 'ip', 'succeeded', 'attempted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'attempted_at' => 'immutable_datetime',
        ];
    }
}
