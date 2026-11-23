<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Exceptions\ValidationException;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $country_code
 * @property string $timezone
 * @property string $currency_code
 * @property string $status
 */
final class LegalEntity extends Model
{
    protected $table = 'legal_entities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'country_code',
        'timezone',
        'currency_code',
        'status',
    ];

    protected static function booted(): void
    {
        self::saving(function (LegalEntity $entity): void {
            $fields = [];

            if (! is_string($entity->timezone) || ! in_array($entity->timezone, DateTimeZone::listIdentifiers(), true)) {
                $fields['timezone'][] = 'The timezone must be a valid IANA timezone identifier.';
            }

            if (! is_string($entity->currency_code) || preg_match('/^[A-Z]{3}$/', $entity->currency_code) !== 1) {
                $fields['currency_code'][] = 'The currency code must be a three-letter ISO 4217 code.';
            }

            if (! is_string($entity->country_code) || preg_match('/^[A-Z]{2}$/', $entity->country_code) !== 1) {
                $fields['country_code'][] = 'The country code must be a two-letter ISO 3166-1 code.';
            }

            if ($fields !== []) {
                throw new ValidationException($fields);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
