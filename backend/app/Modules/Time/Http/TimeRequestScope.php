<?php

declare(strict_types=1);

namespace App\Modules\Time\Http;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\EmploymentSnapshot;
use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Contracts\TimeActorResolver;
use App\Shared\Exceptions\NoActiveEmploymentException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Resolves the caller once per request and applies Time's resource scope. The
 * route capability middleware answers "may this actor use this feature"; this
 * helper answers "may this actor touch this employment/legal entity".
 */
final class TimeRequestScope
{
    public function __construct(
        private readonly TimeActorResolver $actors,
        private readonly EmploymentDirectory $employments,
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

        $snapshot = $this->employments->snapshot($actor->tenantId, $employmentId, $businessDate);

        if ($snapshot === null || ! $actor->canReadEmployment($employmentId, $snapshot->legalEntityId)) {
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

    public function assertLegalEntity(TimeActor $actor, int $legalEntityId, string $businessDate): void
    {
        if (! in_array($legalEntityId, $this->legalEntityIds($actor, $businessDate), true)) {
            throw new NotFoundOrForbiddenException;
        }
    }

    /**
     * @return list<int>
     */
    public function legalEntityIds(TimeActor $actor, string $businessDate): array
    {
        $ids = $actor->legalEntityIds;

        if ($actor->employmentId !== null) {
            $own = $this->employments->snapshot($actor->tenantId, $actor->employmentId, $businessDate);

            if ($own !== null) {
                $ids[] = $own->legalEntityId;
            }
        }

        foreach ($actor->managedEmploymentIds as $managedEmploymentId) {
            $managed = $this->employments->snapshot($actor->tenantId, $managedEmploymentId, $businessDate);

            if ($managed !== null) {
                $ids[] = $managed->legalEntityId;
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function validateDate(?string $value, string $field, bool $required = true): string
    {
        if ($value === null || $value === '') {
            if (! $required) {
                return BusinessDate::today((string) config('hrms.default_timezone', 'Asia/Shanghai'), app(\App\Shared\Time\Clock::class))->value();
            }

            throw new ValidationException([$field => ["The {$field} field is required."]]);
        }

        $date = BusinessDate::tryFrom($value);

        if ($date === null) {
            throw new ValidationException([$field => ["The {$field} field must be a real calendar date in YYYY-MM-DD format."]]);
        }

        return $date->value();
    }
}
