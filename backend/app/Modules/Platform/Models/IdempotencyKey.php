<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $response_body_json
 */
final class IdempotencyKey extends Model
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const UPDATED_AT = null;

    protected $table = 'idempotency_keys';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'principal_id',
        'scope',
        'key',
        'request_hash',
        'status',
        'response_status',
        'response_body_json',
        'resource_type',
        'resource_id',
        'expires_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body_json' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
