<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Organization\Contracts\CompanyProfileWriter;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single-company settings surface. Reads the Organization-owned singleton and
 * delegates writes to the Organization writer; Platform renders the result.
 */
final class CompanyProfileController
{
    public function __construct(
        private readonly CompanyProfileReader $reader,
        private readonly CompanyProfileWriter $writer,
        private readonly PrincipalResolver $principals,
    ) {}

    public function show(): JsonResponse
    {
        $profile = $this->reader->current();

        return ApiResponse::ok([
            'name' => $profile->name,
            'country_code' => $profile->countryCode,
            'timezone' => $profile->timezone,
            'currency_code' => $profile->currencyCode,
            'calendar_policy' => $profile->calendarPolicy,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $actor = $this->principals->resolve();

        return ApiResponse::ok($this->writer->saveProfile(
            [
                'name' => trim((string) $data['name']),
                'country_code' => (string) $data['country_code'],
                'timezone' => (string) $data['timezone'],
                'currency_code' => (string) $data['currency_code'],
            ],
            $actor->userId(),
            (string) $request->header('Idempotency-Key', ''),
        ));
    }
}
