<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only department headcount fact (M05). It lives beside its writer
 * because the module closure grants this service/model pair, while the
 * general Models directory is outside the change boundary.
 *
 * @property int $id
 * @property int $org_unit_id
 * @property int $headcount_limit
 * @property string $effective_from
 */
final class OrgUnitHeadcountVersion extends Model
{
    protected $table = 'org_unit_headcount_versions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_unit_id',
        'headcount_limit',
        'effective_from',
        'recorded_at',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'org_unit_id' => 'integer',
            'headcount_limit' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'recorded_by' => 'integer',
        ];
    }
}
