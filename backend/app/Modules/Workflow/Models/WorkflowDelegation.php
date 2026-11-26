<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $proxy_user_id
 * @property int $principal_user_id
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable $valid_to
 * @property int|null $max_amount_minor
 * @property array<string, mixed>|null $scope_json
 * @property bool $active
 * @property \Carbon\CarbonImmutable|null $revoked_at
 */
final class WorkflowDelegation extends Model
{
    protected $table = 'workflow_delegations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'proxy_user_id',
        'principal_user_id',
        'valid_from',
        'valid_to',
        'max_amount_minor',
        'scope_json',
        'active',
        'revoked_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proxy_user_id' => 'integer',
            'principal_user_id' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'max_amount_minor' => 'integer',
            'scope_json' => 'array',
            'active' => 'boolean',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
