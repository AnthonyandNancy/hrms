<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail records are immutable evidence. Eloquent mutations are blocked
 * here in addition to the absence of any update/delete HTTP surface.
 *
 * @property array<string, mixed>|null $before_json
 * @property array<string, mixed>|null $after_json
 * @property array<string, mixed>|null $metadata_json
 */
final class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'audit_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'actor_type',
        'action',
        'aggregate_type',
        'aggregate_id',
        'before_json',
        'after_json',
        'metadata_json',
        'ip',
        'user_agent',
        'request_id',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \DomainException('Audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new \DomainException('Audit events are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'metadata_json' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
