<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rebuildable reporting read model. Reporting is the sole writer. Rows are
 * produced only from module Contracts and are queried exclusively through the
 * same authorizer-constrained builder used for aggregates and drill-downs.
 */
final class ProjectionRow extends Model
{
    protected $table = 'reporting_projection_rows';

    /** @var list<string> */
    protected $fillable = [
        'source_projection',
        'business_date',
        'metric_code',
        'entity_type',
        'entity_id',
        'employment_id',
        'org_unit_id',
        'label',
        'secondary_label',
        'status',
        'numeric_value',
        'attributes_json',
        'source_hash',
        'computed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date:Y-m-d',
            'employment_id' => 'integer',
            'org_unit_id' => 'integer',
            'numeric_value' => 'integer',
            'attributes_json' => 'array',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /**
     * CapabilityAuthorizer's ORG_UNIT/ORG_SUBTREE anchors need an explicit
     * organization column on the projection model.
     */
    public function authorizationOrgColumn(): string
    {
        return 'org_unit_id';
    }
}
