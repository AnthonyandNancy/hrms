<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class CandidateImport extends Model
{
    protected $table = 'candidate_imports';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'requisition_id', 'source', 'status', 'row_count', 'accepted_count', 'rejected_count', 'rows_json', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'requisition_id' => 'integer',
            'row_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'rows_json' => 'array',
        ];
    }
}
