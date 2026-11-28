<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

/**
 * Runtime reader for the generated page-action contract.
 *
 * The PHP artifact is emitted by scripts/generate_action_manifest.mjs in the
 * same run as docs/generated/page-actions.json and the frontend TypeScript
 * manifest, so the API never parses docs/ at request time.
 */
final class PageActionCatalog
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $pages = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $byKey = null;

    /** @var array<string, list<string>>|null */
    private ?array $pagesByCapability = null;

    /**
     * Object types whose action set is a filtered projection of one page.
     *
     * @var array<string, array{page: string, keys: list<string>|null}>
     */
    private const OBJECT_ACTIONS = [
        'payroll_run' => ['page' => 'payroll-run-detail', 'keys' => null],
        'contract' => ['page' => 'hr-contracts-by-id', 'keys' => null],
        'leave_request' => [
            'page' => 'leave',
            'keys' => ['leave.request.cancel', 'leave.request.revoke'],
        ],
        'overtime_request' => [
            'page' => 'overtime',
            'keys' => ['overtime.request.approve', 'overtime.request.confirm', 'overtime.settlement.create'],
        ],
        'travel_request' => [
            'page' => 'travel',
            'keys' => ['travel.request.approve', 'travel.request.cancel', 'travel.request.change'],
        ],
    ];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function all(): array
    {
        if ($this->pages === null) {
            /** @var mixed $generated */
            $generated = require __DIR__.'/pageActions.generated.php';
            if (! is_array($generated)) {
                throw new \RuntimeException('Generated page action catalog must return an array.');
            }
            $this->pages = $generated;
        }

        return $this->pages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPage(string $pageCode): array
    {
        $actions = $this->all()[$pageCode] ?? [];

        return is_array($actions) ? array_values($actions) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forObject(string $objectType): array
    {
        $projection = self::OBJECT_ACTIONS[$objectType] ?? null;

        if ($projection === null) {
            return [];
        }

        $actions = $this->forPage($projection['page']);

        if ($projection['keys'] === null) {
            return $actions;
        }

        $allowed = array_fill_keys($projection['keys'], true);

        return array_values(array_filter(
            $actions,
            static fn (array $action): bool => isset($allowed[(string) ($action['key'] ?? '')]),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function action(string $key): ?array
    {
        if ($this->byKey === null) {
            $this->byKey = [];

            foreach ($this->all() as $actions) {
                foreach ($actions as $action) {
                    $this->byKey[(string) $action['key']] = $action;
                }
            }
        }

        return $this->byKey[$key] ?? null;
    }

    /**
     * A capability may be reusable by multiple pages. Role authorization must
     * accept a grant when at least one owning page is granted, not fail
     * because the map collapsed the capability to the last page.
     *
     * @return list<string>
     */
    public function pagesForCapability(string $capability): array
    {
        if ($this->pagesByCapability === null) {
            $this->pagesByCapability = [];

            foreach ($this->all() as $pageCode => $actions) {
                foreach ($actions as $action) {
                    $code = (string) ($action['capability'] ?? '');

                    if ($code !== '') {
                        $this->pagesByCapability[$code] ??= [];
                        $this->pagesByCapability[$code][] = (string) $pageCode;
                    }
                }
            }

            foreach ($this->pagesByCapability as &$pages) {
                $pages = array_values(array_unique($pages));
                sort($pages);
            }
            unset($pages);
        }

        return $this->pagesByCapability[$capability] ?? [];
    }

    /**
     * @param  list<string>  $pageCodes
     * @return array<string, list<array<string, mixed>>>
     */
    public function forPageCodes(array $pageCodes): array
    {
        $rows = [];

        foreach (array_values(array_unique($pageCodes)) as $pageCode) {
            $rows[(string) $pageCode] = $this->forPage((string) $pageCode);
        }

        ksort($rows);

        return $rows;
    }
}
