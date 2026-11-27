<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned notice body/template. A new version is appended; an existing
 * version is never rewritten so sent notices retain their frozen definition.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $version
 * @property string $body_template
 * @property array<string, mixed> $variables_json
 * @property string $status
 * @property int $created_by
 */
final class NoticeTemplate extends Model
{
    public const STATUS_PUBLISHED = 'PUBLISHED';

    protected $table = 'notice_templates';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'version',
        'body_template',
        'variables_json',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'variables_json' => 'array',
            'created_by' => 'integer',
        ];
    }
}
