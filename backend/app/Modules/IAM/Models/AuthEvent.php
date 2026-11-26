<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only security event. Update and delete are blocked in addition to the
 * absence of any evidence-editing endpoint.
 *
 * @property \Carbon\CarbonImmutable $occurred_at
 */
final class AuthEvent extends Model
{
    public $timestamps = false;

    protected $table = 'auth_events';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'event_type',
        'result',
        'ip',
        'user_agent',
        'request_id',
        'metadata_json',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new \DomainException('Auth events are append-only.');
        });

        self::deleting(static function (): never {
            throw new \DomainException('Auth events are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'metadata_json' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
