<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string $body
 * @property array<string, mixed> $audience_json
 * @property \Carbon\CarbonImmutable $publish_at
 * @property \Carbon\CarbonImmutable|null $retract_at
 * @property int $created_by
 * @property string $status
 * @property bool $requires_ack
 * @property int $audience_count
 */
final class Notice extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_RETRACTED = 'RETRACTED';

    protected $table = 'notices';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'title',
        'body',
        'audience_json',
        'publish_at',
        'retract_at',
        'created_by',
        'status',
        'requires_ack',
        'audience_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'audience_json' => 'array',
            'publish_at' => 'immutable_datetime',
            'retract_at' => 'immutable_datetime',
            'created_by' => 'integer',
            'requires_ack' => 'boolean',
            'audience_count' => 'integer',
        ];
    }

    /** @return HasMany<NoticeAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(NoticeAcknowledgement::class, 'notice_id');
    }
}
