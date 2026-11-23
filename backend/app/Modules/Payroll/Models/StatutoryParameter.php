<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StatutoryParameter extends Model
{
    use BelongsToTenant;

    protected $table = 'statutory_parameters';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'parameter_set',
        'code',
        'value_decimal',
        'value_int',
        'effective_from',
        'effective_to',
        'version',
        'approved_by',
        'approved_at',
        'evidence_ref',
        'is_demo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_entity_id' => 'integer',
            'value_int' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'is_demo' => 'boolean',
            'effective_from' => 'immutable_date:Y-m-d',
            'effective_to' => 'immutable_date:Y-m-d',
        ];
    }

    /**
     * @return HasMany<TaxBracket, $this>
     */
    public function brackets(): HasMany
    {
        return $this->hasMany(TaxBracket::class, 'parameter_set_id')->orderBy('lower_bound_cents');
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Statutory parameter versions are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Statutory parameter versions are append-only.');
        });
    }
}
