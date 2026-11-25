<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

interface MenuOverrideWriter
{
    /**
     * @return list<array{page_code: string, label: string|null, visible: bool, sort_order: int}>
     */
    public function overrides(int $tenantId): array;

    /**
     * @param  array{page_code: string, label?: string|null, visible?: bool, sort_order?: int}  $data
     * @return array{page_code: string, label: string|null, visible: bool, sort_order: int}
     */
    public function create(int $tenantId, array $data): array;

    /**
     * @param  array{label?: string|null, visible?: bool, sort_order?: int}  $data
     * @return array{page_code: string, label: string|null, visible: bool, sort_order: int}
     */
    public function update(int $tenantId, string $pageCode, array $data): array;

    public function delete(int $tenantId, string $pageCode): bool;
}
