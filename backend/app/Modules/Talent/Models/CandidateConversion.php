<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class CandidateConversion extends Model
{
    protected $table = 'candidate_conversions';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'candidate_id', 'employment_id', 'person_id', 'offer_id', 'legal_entity_id', 'org_unit_id', 'employee_no', 'status', 'recorded_at'];

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
            'candidate_id' => 'integer',
            'employment_id' => 'integer',
            'person_id' => 'integer',
            'offer_id' => 'integer',
            'legal_entity_id' => 'integer',
            'org_unit_id' => 'integer',
        ];
    }
}
