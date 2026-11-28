<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Payroll\Contracts\PayslipNotificationDirectory;
use App\Modules\Payroll\Contracts\PublishedPayslipRef;
use App\Modules\People\Contracts\NoticeAudienceDirectory;
use App\Shared\Time\CompanyBusinessDate;

/**
 * Translates a Payroll publication fact into identifier-only in-app
 * notifications. Payroll remains the writer of payslips; Engagement only
 * consumes its published, amount-free read contract.
 */
final class PayslipNotificationService
{
    public function __construct(
        private readonly PayslipNotificationDirectory $payslips,
        private readonly NotificationService $notifications,
        private readonly NoticeAudienceDirectory $audience,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @return list<int>
     */
    public function notifyRunPublished(int $runId): array
    {
        $payslips = $this->payslips->publishedForRun($runId);

        if ($payslips === []) {
            return [];
        }

        $today = $this->businessDates->today()->value();
        $employmentIds = array_values(array_unique(array_map(
            static fn (PublishedPayslipRef $payslip): int => $payslip->employmentId,
            $payslips,
        )));
        $userIds = $this->audience->userIdsForEmployments($employmentIds, $today);
        $userIdsByEmployment = [];

        // Resolve each employment separately so a user receives only their own
        // published payslip notification, never another employee's identifier.
        foreach ($employmentIds as $employmentId) {
            $resolved = $this->audience->userIdsForEmployments([$employmentId], $today);
            $userIdsByEmployment[$employmentId] = $resolved[0] ?? null;
        }

        $ids = [];

        foreach ($payslips as $payslip) {
            $userId = $userIdsByEmployment[$payslip->employmentId] ?? null;

            if ($userId === null || ! in_array($userId, $userIds, true)) {
                continue;
            }

            $ids = [...$ids, ...$this->notifications->notify(
                userIds: [$userId],
                type: 'PAYSLIP_PUBLISHED',
                payload: ['payslip_id' => $payslip->payslipId],
                eventKey: 'payslip-published:'.$payslip->payslipId,
            )];
        }

        return array_values(array_unique($ids));
    }
}
