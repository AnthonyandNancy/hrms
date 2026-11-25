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
 * @property int|null $approval_case_id
 * @property \Carbon\CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property \Carbon\CarbonImmutable|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_previous_status
 * @property string|null $archive_reason
 * @property int|null $template_id
 * @property int|null $template_version
 * @property string|null $template_code
 */
final class Notice extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_RETRACTED = 'RETRACTED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

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
        'approval_case_id',
        'approved_at',
        'approved_by',
        'archived_at',
        'archived_by',
        'archive_previous_status',
        'archive_reason',
        'template_id',
        'template_version',
        'template_code',
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
            'approval_case_id' => 'integer',
            'approved_at' => 'immutable_datetime',
            'approved_by' => 'integer',
            'archived_at' => 'immutable_datetime',
            'archived_by' => 'integer',
            'template_id' => 'integer',
            'template_version' => 'integer',
        ];
    }

    /** @return HasMany<NoticeAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(NoticeAcknowledgement::class, 'notice_id');
    }
}
