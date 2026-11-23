<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Code-owned report definition materialised as a tenant-scoped read model.
 * fields_json carries the FieldProfile per exportable column.
 */
final class ReportDefinition extends Model
{
    protected $table = 'report_definitions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'source_projection',
        'as_of_supported',
        'fields_json',
        'filters_json',
        'drilldown_route',
        'catalog_version',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'as_of_supported' => 'boolean',
            'fields_json' => 'array',
            'filters_json' => 'array',
            'catalog_version' => 'integer',
            'active' => 'boolean',
        ];
    }
}
