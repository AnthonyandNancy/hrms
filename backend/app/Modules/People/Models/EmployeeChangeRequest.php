<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Employee profile correction request, reviewed as a workflow case.
 */
final class EmployeeChangeRequest extends Model
{
    protected $table = 'employee_change_requests';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'requested_by',
        'status',
        'diff_json',
        'reason',
        'case_id',
        'submitted_at',
        'decided_at',
        'decided_by',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['diff_json' => 'array', 'errors_json' => 'array'];
    }
}
