<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Time\TenantBusinessDate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the caller's current legal entity through the People contract and
 * exposes it as a request attribute for capability middleware. The client
 * cannot choose the anchor: a supplied header is ignored for these routes.
 */
final class ResolveLegalEntityContext
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly TenantBusinessDate $businessDates,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->attributes->get('iam.principal');

        if (! $principal instanceof Principal && app()->bound(Principal::class)) {
            $principal = app(Principal::class);
        }

        if ($principal instanceof Principal) {
            $employmentId = $principal->employmentId()
                ?? $this->people->employmentForUser($principal->tenantId(), $principal->userId());

            if ($employmentId !== null) {
                $today = $this->businessDates->today($principal->tenantId())->value();
                $snapshot = $this->people->employmentSnapshot($principal->tenantId(), $employmentId, $today);

                if ($snapshot !== null) {
                    $request->merge(['legal_entity_id' => $snapshot->legalEntityId]);
                    $request->attributes->set('resolved_legal_entity_id', $snapshot->legalEntityId);
                }
            }
        }

        return $next($request);
    }
}
