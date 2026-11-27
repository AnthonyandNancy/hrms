<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class CandidateConversion extends Model
{
    protected $table = 'candidate_conversions';

    /** @var list<string> */
    protected $fillable = ['candidate_id', 'employment_id', 'person_id', 'offer_id', 'org_unit_id', 'employee_no', 'onboarding_case_id', 'status', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'candidate_id' => 'integer',
            'employment_id' => 'integer',
            'person_id' => 'integer',
            'offer_id' => 'integer',
            'org_unit_id' => 'integer',
            'onboarding_case_id' => 'integer',
        ];
    }
}
