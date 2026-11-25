<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

interface MenuOverrideReader
{
    /**
     * @return list<array{page_code: string, label: string|null, visible: bool, sort_order: int}>
     */
    public function overrides(int $tenantId): array;
}
