<?php

declare(strict_types=1);

namespace App\Modules\Time\Http;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\EmploymentSnapshot;
use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Contracts\TimeActorResolver;
use App\Shared\Exceptions\NoActiveEmploymentException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Resolves the caller once per request and applies Time's resource scope. The
 * route capability middleware answers "may this actor use this feature"; this
 * helper answers "may this actor touch this employment". Single company means
 * there is no tenant or legal-entity predicate left.
 */
final class TimeRequestScope
{
    public function __construct(
        private readonly TimeActorResolver $actors,
        private readonly EmploymentDirectory $employments,
        private readonly CompanyProfileReader $company,
        private readonly Clock $clock,
    ) {}

    public function actor(Request $request): TimeActor
    {
        $actor = $this->actors->resolve($request);

        if ($actor === null) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    public function employment(TimeActor $actor, int $employmentId, string $businessDate): EmploymentSnapshot
    {
        BusinessDate::parse($businessDate);

        if ($actor->employmentId === null && $employmentId <= 0) {
            throw new NoActiveEmploymentException('time.self');
        }

        $snapshot = $this->employments->snapshot($employmentId, $businessDate);

        if ($snapshot === null || ! $actor->canReadEmployment($employmentId)) {
            throw new NotFoundOrForbiddenException;
        }

        return $snapshot;
    }

    /**
     * Resolve the caller's own employment or fail with the explicit SELF
     * business error. Never substitute 0: that turns a state problem into a
     * misleading 404.
     */
    public function selfEmployment(TimeActor $actor, string $businessDate, ?int $requestedEmploymentId = null): EmploymentSnapshot
    {
        $employmentId = $requestedEmploymentId ?? $actor->employmentId;

        if ($employmentId === null || $employmentId <= 0) {
            throw new NoActiveEmploymentException('SELF');
        }

        return $this->employment($actor, $employmentId, $businessDate);
    }

    /**
     * The company scope used to be proven by a legal-entity id set. There is
     * exactly one company now, so a COMPANY/HR/ADMIN actor is in scope and a
     * SELF/MANAGED actor is not.
     */
    public function assertCompanyScope(TimeActor $actor): void
    {
        if (! in_array($actor->scope, ['COMPANY', 'HR', 'ADMIN'], true)) {
            throw new NotFoundOrForbiddenException;
        }
    }

    public function canReadCompanyScope(TimeActor $actor): bool
    {
        return in_array($actor->scope, ['COMPANY', 'HR', 'ADMIN'], true);
    }

    public function validateDate(?string $value, string $field, bool $required = true): string
    {
        if ($value === null || $value === '') {
            if (! $required) {
                return $this->today()->value();
            }

            throw new ValidationException([$field => ["The {$field} field is required."]]);
        }

        $date = BusinessDate::tryFrom($value);

        if ($date === null) {
            throw new ValidationException([$field => ["The {$field} field must be a real calendar date in YYYY-MM-DD format."]]);
        }

        return $date->value();
    }

    /**
     * Business dates are company dates: use the company profile timezone and
     * the shared clock, never a server-local or per-tenant timezone.
     */
    public function today(): BusinessDate
    {
        return BusinessDate::today($this->company->current()->timezone, $this->clock);
    }

    public function resolveDate(?string $value, string $field = 'as_of'): string
    {
        return $this->validateDate($value, $field, false);
    }
}
