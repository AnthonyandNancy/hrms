<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Effective-dated travel policy version. Publishing appends a row; no update
 * path exists for a historical version.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property string $city_tier
 * @property int $hotel_cap_cents
 * @property int $transport_cap_cents
 * @property \Carbon\CarbonImmutable $effective_from
 * @property int $version
 * @property string $status
 * @property int $created_by
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class TravelPolicy extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'travel_policies';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'city_tier',
        'hotel_cap_cents',
        'transport_cap_cents',
        'effective_from',
        'version',
        'status',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'legal_entity_id' => 'integer',
            'hotel_cap_cents' => 'integer',
            'transport_cap_cents' => 'integer',
            'effective_from' => 'immutable_date:Y-m-d',
            'version' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
