<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Adapters;

use App\Modules\Engagement\Services\PayslipNotificationService;
use App\Modules\Payroll\Contracts\PayslipPublicationNotifier;

/**
 * Engagement adapter for Payroll's outbound publication seam. It is invoked
 * after commit and only receives the run identifier.
 */
final class PayslipPublicationNotifierAdapter implements PayslipPublicationNotifier
{
    public function __construct(private readonly PayslipNotificationService $notifications) {}

    public function notifyRunPublished(int $runId): void
    {
        $this->notifications->notifyRunPublished($runId);
    }
}
