<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Contracts\RecruitmentFunnelDirectory;
use App\Modules\Talent\Contracts\RecruitmentFunnelRow;

final class UnavailableRecruitmentFunnelDirectory implements RecruitmentFunnelDirectory
{
    public function available(): bool
    {
        return false;
    }

    public function unavailableReason(): string
    {
        return 'Talent requisition and candidate tables exist, but this reference binding does not yet publish a Reporting funnel projection; the recruitment funnel is unavailable rather than inferred from raw candidate rows.';
    }

    /** @return list<RecruitmentFunnelRow> */
    public function funnel(string $asOf): array
    {
        return [];
    }
}
