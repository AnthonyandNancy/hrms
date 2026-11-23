<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Models;

use Illuminate\Database\Eloquent\Model;

final class EmploymentContractVersion extends Model
{
    protected $table = 'employment_contract_versions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'terms_version',
        'document_version',
        'file_object_id',
        'effective_from',
        'effective_to',
        'expires_at',
        'recorded_at',
        'source_type',
        'source_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'employment_id' => 'integer',
            'terms_version' => 'integer',
            'document_version' => 'integer',
            'file_object_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
