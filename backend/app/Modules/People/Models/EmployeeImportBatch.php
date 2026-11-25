<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Async employee import validation/commit batch.
 */
final class EmployeeImportBatch extends Model
{
    protected $table = 'employee_import_batches';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'uploaded_by',
        'status',
        'original_name',
        'total_rows',
        'valid_rows',
        'failed_rows',
        'errors_json',
        'validated_at',
        'committed_at',
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
