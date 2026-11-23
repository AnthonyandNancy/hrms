<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models\Concerns;

use App\Shared\RequestContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant isolation for payroll rows. Context 0 is reserved for console work
 * where the caller passes an explicit tenant to every service method.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('payroll_tenant', static function (Builder $query): void {
            $tenantId = RequestContext::tenantId();

            if ($tenantId > 0) {
                $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(static function (Model $model): void {
            $tenantId = RequestContext::tenantId();

            if ($model->getAttribute('tenant_id') === null && $tenantId > 0) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }
}
