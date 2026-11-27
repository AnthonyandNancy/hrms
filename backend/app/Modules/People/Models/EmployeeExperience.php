<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Employee work history.
 */
final class EmployeeExperience extends Model
{
    protected $table = 'employee_experiences';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'company',
        'title',
        'start_date',
        'end_date',
        'leave_reason',
        'notes',
        'recorded_at',
        'superseded_at',
        'created_at',
        'updated_at',
    ];
}
