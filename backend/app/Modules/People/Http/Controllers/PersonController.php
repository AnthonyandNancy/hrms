<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\People\Services\PersonPrivacy;
use App\Modules\People\Services\PersonService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PersonController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly PersonPrivacy $privacy,
    ) {}

    public function store(Request $request, PersonService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());

        if (! $this->authorizer->allows($principal, 'people.employee.manage', AnchorRef::global(), $today->value())) {
            throw new AuthorizationException('Forbidden.');
        }

        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:120'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'id_type' => ['nullable', 'string', 'max:32'],
            'id_number' => ['nullable', 'string', 'max:128', 'required_with:id_type'],
            'birth_date' => ['nullable', new BusinessDateRule],
            'gender' => ['nullable', 'string', 'max:32'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:254'],
        ]);

        $person = $service->open($principal->tenantId(), $data, $principal->userId());

        return ApiResponse::created($this->privacy->project($person, 'P1_INTERNAL'));
    }
}
