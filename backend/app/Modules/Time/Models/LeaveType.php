<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property bool $is_statutory
 * @property bool $is_annual
 * @property string $unit
 * @property string|null $annual_entitlement_days
 * @property array<string, mixed>|null $expiry_rule_json
 */
final class LeaveType extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'is_statutory',
        'is_annual',
        'unit',
        'annual_entitlement_days',
        'requires_attachment',
        'allow_negative',
        'expiry_rule_json',
        'legal_basis',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_statutory' => 'boolean',
            'is_annual' => 'boolean',
            'requires_attachment' => 'boolean',
            'allow_negative' => 'boolean',
            'annual_entitlement_days' => 'string',
            'expiry_rule_json' => 'array',
            'version' => 'integer',
        ];
    }
}
