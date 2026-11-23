<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property array<string, mixed> $payload_json
 */
final class OutboxEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'outbox_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version',
        'payload_json',
        'occurred_at',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'aggregate_version' => 'integer',
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
