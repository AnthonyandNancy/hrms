<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned employment-contract template. A template code has at most one
 * PUBLISHED version; publishing the next version archives the prior one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property int $version
 * @property string $status
 * @property string $body
 * @property CarbonImmutable|null $published_at
 */
final class ContractTemplate extends Model
{
    public const STATUSES = ['DRAFT', 'PUBLISHED', 'ARCHIVED'];

    protected $table = 'contract_templates';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'version',
        'status',
        'body',
        'published_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }
}
