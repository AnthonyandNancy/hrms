<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A travel expense claim. It never creates a payroll item or payment; the
 * amount is stored as integer minor units and `payment_status` is always
 * CLAIM_ONLY.
 *
 * @property int $id
 * @property int $employment_id
 * @property int $travel_id
 * @property string $category
 * @property int $amount_cents
 * @property int|null $receipt_file_id
 * @property string $status
 * @property string|null $note
 * @property int $created_by
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class TravelExpense extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_CLAIMED = 'CLAIMED';

    public const CATEGORIES = ['TRANSPORT', 'LODGING', 'MEALS', 'OTHER'];

    protected $table = 'travel_expenses';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'travel_id',
        'category',
        'amount_cents',
        'receipt_file_id',
        'status',
        'note',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_id' => 'integer',
            'travel_id' => 'integer',
            'amount_cents' => 'integer',
            'receipt_file_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
