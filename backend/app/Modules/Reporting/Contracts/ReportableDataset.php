<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use App\Modules\IAM\Contracts\Principal;

interface ReportableDataset
{
    /**
     * Ensure reporting projections for this as-of are present.
     */
    public function ensure(string $asOf): void;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(Principal $principal, string $sourceProjection, string $asOf, array $filters = []): array;
}
