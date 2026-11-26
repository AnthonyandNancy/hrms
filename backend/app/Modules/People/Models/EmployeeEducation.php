<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employment_id
 */
final class EmployeeEducation extends Model
{
    protected $table = 'employee_educations';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'school',
        'degree',
        'major',
        'start_date',
        'end_date',
        'notes',
        'recorded_at',
        'superseded_at',
        'created_at',
        'updated_at',
    ];
}
