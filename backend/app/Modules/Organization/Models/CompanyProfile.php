<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Exceptions\ValidationException;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $country_code
 * @property string $timezone
 * @property string $currency_code
 * @property array<string, mixed>|null $calendar_policy_json
 */
final class CompanyProfile extends Model
{
    protected $table = 'company_profile';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'country_code',
        'timezone',
        'currency_code',
        'calendar_policy_json',
    ];

    protected static function booted(): void
    {
        self::saving(function (CompanyProfile $profile): void {
            $fields = [];

            if (! is_string($profile->timezone) || ! in_array($profile->timezone, DateTimeZone::listIdentifiers(), true)) {
                $fields['timezone'][] = 'The timezone must be a valid IANA timezone identifier.';
            }

            if (! is_string($profile->currency_code) || preg_match('/^[A-Z]{3}$/', $profile->currency_code) !== 1) {
                $fields['currency_code'][] = 'The currency code must be a three-letter ISO 4217 code.';
            }

            if (! is_string($profile->country_code) || preg_match('/^[A-Z]{2}$/', $profile->country_code) !== 1) {
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
            'calendar_policy_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
