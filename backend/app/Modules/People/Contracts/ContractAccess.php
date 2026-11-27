<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Database\QueryException;

/**
 * Single access seam for concrete contract and employment resources.
 *
 * Row scope is always resolved by IAM's Authorizer, never by trusting a route
 * employment id or by checking to whom the current user is related. Two query
 * shapes are tried because row scope has two useful sources:
 *
 *  - Employment carries the employment key but no org unit key;
 *  - Assignment carries both the employment key and the org unit key.
 *
 * SELF/MANAGED/COMPANY grants are fully answered by the Employment query.
 * ORG_UNIT/ORG_SUBTREE grants are answered by the Assignment query. If a
 * custom or production authorizer cannot constrain a shape, that shape simply
 * yields no grant; it is never widened to a feature-level `allows()` check.
 */
final class ContractAccess
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    public function contract(
        Principal $principal,
        int $contractId,
        string $capability,
        ?string $asOf = null,
    ): EmploymentContract {
        $on = $asOf ?? $this->businessDates->today()->value();
        $contract = EmploymentContract::query()->whereKey($contractId)->first();

        if (! $contract instanceof EmploymentContract
            || ! $this->employmentVisible($principal, (int) $contract->employment_id, $capability, $on)) {
            throw new NotFoundOrForbiddenException;
        }

        return $contract;
    }

    public function employmentVisible(Principal $principal, int $employmentId, string $capability, ?string $asOf = null): bool
    {
        $on = $asOf ?? $this->businessDates->today()->value();

        if ($employmentId <= 0) {
            return false;
        }

        try {
            if ($this->authorizer->constrain(
                Employment::query()->whereKey($employmentId),
                $principal,
                $capability,
                $on,
            )->exists()) {
                return true;
            }
        } catch (QueryException) {
            // ORG_UNIT / ORG_SUBTREE predicates refer to an org column this
            // table does not carry. The Assignment shape below is the right
            // projection for those anchors.
        }

        try {
            return $this->authorizer->constrain(
                Assignment::query()
                    ->where('employment_id', $employmentId)
                    ->where('valid_from', '<=', $on)
                    ->where('valid_to', '>', $on)
                    ->select('employment_id')
                    ->limit(1),
                $principal,
                $capability,
                $on,
            )->exists();
        } catch (QueryException) {
            // A custom authorizer that cannot constrain Assignment cannot
            // prove any org-scoped visibility. Default deny; never fall back
            // to a capability-only `allows()` answer.
            return false;
        }
    }
}
