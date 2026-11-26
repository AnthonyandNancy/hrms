<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\CompanyProfileInfo;
use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Organization\Models\CompanyProfile;

final class EloquentCompanyProfileReader implements CompanyProfileReader
{
    public function current(): CompanyProfileInfo
    {
        $profile = CompanyProfile::query()->orderBy('id')->first();

        if ($profile === null) {
            return new CompanyProfileInfo(
                name: (string) config('app.name', 'HRMS'),
                countryCode: 'CN',
                timezone: (string) config('hrms.default_timezone', 'Asia/Shanghai'),
                currencyCode: 'CNY',
            );
        }

        return new CompanyProfileInfo(
            name: (string) $profile->name,
            countryCode: (string) $profile->country_code,
            timezone: (string) $profile->timezone,
            currencyCode: (string) $profile->currency_code,
            calendarPolicy: is_array($profile->calendar_policy_json) ? $profile->calendar_policy_json : null,
        );
    }
}
