<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollReceiptType;
use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $run_id
 * @property PayrollReceiptType $type
 * @property string|null $file_hash
 * @property string|null $external_reference
 * @property \Carbon\CarbonImmutable $received_at
 * @property \Carbon\CarbonImmutable|null $reconciled_at
 * @property array<string, mixed>|null $metadata_json
 */
final class PayrollReceipt extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'payroll_receipts';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'run_id',
        'type',
        'file_hash',
        'external_reference',
        'received_at',
        'reconciled_at',
        'metadata_json',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PayrollReceiptType::class,
            'received_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
