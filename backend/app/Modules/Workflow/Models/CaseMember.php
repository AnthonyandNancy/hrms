<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $case_id
 * @property int $user_id
 * @property string $role
 */
final class CaseMember extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'case_members';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'user_id',
        'role',
        'created_at',
    ];

    /**
     * @return BelongsTo<CaseModel, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseModel::class, 'case_id');
    }
}
