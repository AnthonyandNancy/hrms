<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class Offer extends Model
{
    protected $table = 'offers';

    /** @var list<string> */
    protected $fillable = [
        'candidate_id', 'requisition_id', 'version', 'status', 'base_salary_minor',
        'salary_currency', 'salary_scheme_code', 'start_date', 'case_id', 'onboarding_case_id', 'accepted_at',
        'declined_at', 'superseded_by_id', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_id' => 'integer',
            'requisition_id' => 'integer',
            'version' => 'integer',
            'base_salary_minor' => 'integer',
            'case_id' => 'integer',
            'onboarding_case_id' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'superseded_by_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
