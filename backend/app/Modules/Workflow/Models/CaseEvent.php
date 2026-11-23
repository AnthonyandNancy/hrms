<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only workflow timeline. Business effect events are projected here so
 * the timeline API remains one chronological read.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $case_id
 * @property string $event_type
 * @property int|null $actor_user_id
 * @property array<string, mixed> $payload_json
 * @property \Carbon\CarbonImmutable $occurred_at
 */
final class CaseEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'case_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'event_type',
        'actor_user_id',
        'payload_json',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Case events are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Case events are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'payload_json' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CaseModel, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseModel::class, 'case_id');
    }
}
