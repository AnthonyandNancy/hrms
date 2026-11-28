<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Encrypted People-owned PII draft used while an onboarding case awaits
 * approval. Lifecycle may reference only the opaque draft id.
 *
 * @property int $id
 * @property string $employee_no
 * @property string $idempotency_key
 * @property int|null $case_id
 * @property string $status
 * @property string $payload_ciphertext
 * @property int $created_by
 * @property \Carbon\CarbonImmutable|null $consumed_at
 */
final class OnboardingDraft extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_CONSUMED = 'CONSUMED';

    protected $table = 'people_onboarding_drafts';

    /** @var list<string> */
    protected $fillable = [
        'employee_no',
        'pending_employee_no',
        'idempotency_key',
        'case_id',
        'status',
        'payload_ciphertext',
        'created_by',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'case_id' => 'integer',
            'created_by' => 'integer',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
