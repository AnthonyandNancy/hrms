<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class RoleCapability extends Model
{
    protected $table = 'role_capabilities';

    /** @var list<string> */
    protected $fillable = [
        'role_code',
        'capability_code',
        'anchor_type',
        'field_profile',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
