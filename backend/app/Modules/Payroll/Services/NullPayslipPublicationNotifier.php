<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayslipPublicationNotifier;

/**
 * Default no-op outbound seam for module tests and isolated installations.
 * Engagement binds the real adapter in the application composition root.
 */
final class NullPayslipPublicationNotifier implements PayslipPublicationNotifier
{
    public function notifyRunPublished(int $runId): void
    {
        // Intentionally empty: publication itself must never depend on a
        // notification transport being configured.
    }
}
