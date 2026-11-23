<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $tenant_id
 * @property int $user_id
 * @property int $employment_id
 * @property string $valid_from
 * @property string $valid_to
 */
final class EmploymentUserLink extends Model
{
    protected $table = 'employment_user_links';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'employment_id',
        'valid_from',
        'valid_to',
        'recorded_at',
        'source_type',
        'source_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'employment_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
