<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\MenuOverrideReader;
use App\Modules\Platform\Contracts\MenuOverrideWriter;
use App\Modules\Platform\Models\MenuOverride;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;

final class MenuOverrideService implements MenuOverrideReader, MenuOverrideWriter
{
    public function overrides(): array
    {
        return MenuOverride::query()
            ->orderBy('sort_order')
            ->orderBy('page_code')
            ->get()
            ->map(fn (MenuOverride $override): array => $this->row($override))
            ->all();
    }

    public function create(array $data): array
    {
        $exists = MenuOverride::query()
            ->where('page_code', (string) $data['page_code'])
            ->exists();

        if ($exists) {
            throw new ConflictException('This page already has a menu override.', ['reason' => 'MENU_OVERRIDE_EXISTS']);
        }

        /** @var MenuOverride $override */
        $override = MenuOverride::query()->create([
            'page_code' => (string) $data['page_code'],
            'label' => $data['label'] ?? null,
            'visible' => (bool) ($data['visible'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->row($override);
    }

    public function update(string $pageCode, array $data): array
    {
        /** @var MenuOverride|null $override */
        $override = MenuOverride::query()
            ->where('page_code', $pageCode)
            ->first();

        if ($override === null) {
            throw new NotFoundOrForbiddenException;
        }

        $override->fill([
            ...array_key_exists('label', $data) ? ['label' => $data['label']] : [],
            ...array_key_exists('visible', $data) ? ['visible' => (bool) $data['visible']] : [],
            ...array_key_exists('sort_order', $data) ? ['sort_order' => (int) $data['sort_order']] : [],
        ])->save();

        return $this->row($override->refresh());
    }

    public function delete(string $pageCode): bool
    {
        $deleted = MenuOverride::query()
            ->where('page_code', $pageCode)
            ->delete();

        if ($deleted === 0) {
            throw new NotFoundOrForbiddenException;
        }

        return true;
    }

    /**
     * @return array{page_code: string, label: string|null, visible: bool, sort_order: int}
     */
    private function row(MenuOverride $override): array
    {
        return [
            'page_code' => (string) $override->page_code,
            'label' => $override->label !== null ? (string) $override->label : null,
            'visible' => (bool) $override->visible,
            'sort_order' => (int) $override->sort_order,
        ];
    }
}
