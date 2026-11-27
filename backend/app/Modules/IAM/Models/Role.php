<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class Role extends Model
{
    public $incrementing = false;

    protected $table = 'roles';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_template', 'owner', 'purpose', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'owner' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
