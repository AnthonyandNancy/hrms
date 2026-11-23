<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * In-app notification. Payloads carry identifiers only and never PII.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $type
 * @property array<string, mixed> $payload_json
 * @property \Carbon\CarbonImmutable|null $read_at
 */
final class Notification extends Model
{
    protected $table = 'notifications';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'payload_json',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'payload_json' => 'array',
            'read_at' => 'immutable_datetime',
        ];
    }
}
