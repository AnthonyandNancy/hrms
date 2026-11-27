<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollItemVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Payroll item catalog command/read boundary.
 *
 * payroll_items stores identity only (code + status). Every mutable attribute
 * is effective-dated on payroll_item_versions. Appending creates a new row and
 * structurally closes the previous effective_to; business facts on old rows are
 * never rewritten. Retirement stamps the current version and identity without
 * deleting history.
 */
final class PayrollItemService
{
    /**
     * @var list<string>
     */
    private const FROZEN_RUN_STATUSES = ['PUBLISHED', 'PAID', 'CLOSED'];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return PayrollItem::query()
            ->with('versions')
            ->orderBy('code')
            ->get()
            ->flatMap(function (PayrollItem $item): array {
                $latestVersion = (int) $item->versions->max('version');

                return $item->versions
                    ->sortByDesc('version')
                    ->map(fn (PayrollItemVersion $version): array => [
                        ...$this->payload($item, $version),
                        'is_current' => (int) $version->version === $latestVersion,
                        'is_retired' => $item->status === 'RETIRED',
                    ])
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $actorUserId, array $data): PayrollItemVersion
    {
        $code = strtoupper((string) $data['code']);
        $effectiveFrom = $this->effectiveFrom($data);
        $this->assertNotFrozen($effectiveFrom);

        try {
            return DB::transaction(function () use ($actorUserId, $data, $code, $effectiveFrom): PayrollItemVersion {
                if (PayrollItem::query()->where('code', $code)->lockForUpdate()->exists()) {
                    throw new ConflictException('A payroll item with this code already exists.', [
                        'reason' => 'PAYROLL_ITEM_CODE_EXISTS',
                        'code' => $code,
                    ]);
                }

                $item = PayrollItem::query()->create([
                    'code' => $code,
                    'status' => 'ACTIVE',
                ]);

                $version = PayrollItemVersion::query()->create([
                    'payroll_item_id' => (int) $item->id,
                    'version' => 1,
                    'name' => trim((string) $data['name']),
                    'type' => (string) $data['type'],
                    'taxable' => (bool) ($data['taxable'] ?? true),
                    'insurable' => (bool) ($data['insurable'] ?? true),
                    'calculation' => (string) ($data['calculation'] ?? 'FIXED'),
                    'formula_json' => $data['formula_json'] ?? null,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $this->effectiveTo($data, $effectiveFrom),
                    'retired_at' => null,
                    'retire_reason' => null,
                    'status' => 'ACTIVE',
                    'recorded_at' => $this->clock->now(),
                ]);

                $this->audit->record(
                    actorUserId: $actorUserId,
                    action: 'payroll.item.created',
                    aggregateType: 'payroll_item',
                    aggregateId: (int) $item->id,
                    after: ['code' => $code, 'version' => 1, 'effective_from' => $effectiveFrom],
                );

                return $version->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('A payroll item with this code already exists.', [
                'reason' => 'PAYROLL_ITEM_CODE_EXISTS',
                'code' => $code,
            ]);
        }
    }

    /**
     * Append a new effective-dated version. Client-supplied version numbers are
     * never trusted; the server allocates max(version)+1 while holding the
     * payroll_items row lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function appendVersion(int $actorUserId, int $itemId, array $data): PayrollItemVersion
    {
        $effectiveFrom = BusinessDate::from((string) $data['effective_from'])->value();
        $this->assertNotFrozen($effectiveFrom);

        return DB::transaction(function () use ($actorUserId, $itemId, $data, $effectiveFrom): PayrollItemVersion {
            $item = $this->lockedItem($itemId);

            if ((string) $item->status !== 'ACTIVE') {
                throw new ConflictException('A retired payroll item cannot receive a new version.', [
                    'reason' => 'PAYROLL_ITEM_RETIRED',
                    'item_id' => (int) $item->id,
                ]);
            }

            $current = PayrollItemVersion::query()
                ->where('payroll_item_id', (int) $item->id)
                ->whereNull('retired_at')
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new ConflictException('The payroll item has no active version to extend.', [
                    'reason' => 'PAYROLL_ITEM_VERSION_MISSING',
                    'item_id' => (int) $item->id,
                ]);
            }

            $this->assertAppendOrder($current, $effectiveFrom);
            $effectiveTo = $this->effectiveTo($data, $effectiveFrom);

            if ($effectiveTo !== BusinessDate::OPEN_END) {
                $this->assertNotFrozen($effectiveTo);
            }

            // Structural supersession only: shorten the previous range to the
            // day before the new version. Name/type/tax/formula and the start
            // date stay untouched; historical facts remain byte-for-byte
            // readable and a new version is the only way to change them.
            $currentTo = \Carbon\CarbonImmutable::parse((string) $current->effective_to)->format('Y-m-d');
            $previousTo = BusinessDate::from($effectiveFrom)->addDays(-1)->value();

            if ($currentTo === BusinessDate::OPEN_END || $previousTo < $currentTo) {
                $current->forceFill(['effective_to' => $previousTo])->save();
            }

            $versionNumber = ((int) PayrollItemVersion::query()
                ->where('payroll_item_id', (int) $item->id)
                ->max('version')) + 1;

            $version = PayrollItemVersion::query()->create([
                'payroll_item_id' => (int) $item->id,
                'version' => $versionNumber,
                'name' => trim((string) ($data['name'] ?? $current->name)),
                'type' => (string) ($data['type'] ?? $current->type->value),
                'taxable' => (bool) ($data['taxable'] ?? $current->taxable),
                'insurable' => (bool) ($data['insurable'] ?? $current->insurable),
                'calculation' => (string) ($data['calculation'] ?? $current->calculation),
                'formula_json' => $data['formula_json'] ?? $current->formula_json,
                'sort_order' => (int) ($data['sort_order'] ?? $current->sort_order),
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'retired_at' => null,
                'retire_reason' => null,
                'status' => 'ACTIVE',
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.item.versioned',
                aggregateType: 'payroll_item',
                aggregateId: (int) $item->id,
                before: ['version' => (int) $current->version],
                after: [
                    'version' => $versionNumber,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'code' => (string) $item->code,
                ],
            );

            return $version->refresh();
        });
    }

    public function retire(int $actorUserId, int $itemId, string $reason): PayrollItem
    {
        return DB::transaction(function () use ($actorUserId, $itemId, $reason): PayrollItem {
            $item = $this->lockedItem($itemId);

            // Same-command replay: already retired is a successful no-op.
            if ((string) $item->status === 'RETIRED') {
                return $item->refresh();
            }

            $current = PayrollItemVersion::query()
                ->where('payroll_item_id', (int) $item->id)
                ->whereNull('retired_at')
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new ConflictException('The payroll item has no active version to retire.', [
                    'reason' => 'PAYROLL_ITEM_VERSION_MISSING',
                    'item_id' => (int) $item->id,
                ]);
            }

            $this->assertNotFrozen($this->businessDates->today()->value());
            $this->assertNotFrozen(\Carbon\CarbonImmutable::parse((string) $current->effective_from)->format('Y-m-d'));
            $this->assertNoFutureSchemeReference($item);

            $now = $this->clock->now();
            $current->forceFill([
                'status' => 'RETIRED',
                'retired_at' => $now,
                'retire_reason' => trim($reason),
            ])->save();

            $item->forceFill(['status' => 'RETIRED'])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.item.retired',
                aggregateType: 'payroll_item',
                aggregateId: (int) $item->id,
                before: ['status' => 'ACTIVE', 'version' => (int) $current->version],
                after: ['status' => 'RETIRED', 'reason' => trim($reason)],
            );

            return $item->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(PayrollItem $item, PayrollItemVersion $version): array
    {
        return [
            'id' => (int) $item->id,
            'item_version_id' => (int) $version->id,
            'code' => (string) $item->code,
            'version' => (int) $version->version,
            'status' => (string) $item->status,
            'version_status' => (string) $version->status,
            'name' => (string) $version->name,
            'type' => $version->type->value,
            'taxable' => (bool) $version->taxable,
            'insurable' => (bool) $version->insurable,
            'calculation' => (string) $version->calculation,
            'formula_json' => $version->formula_json,
            'sort_order' => (int) $version->sort_order,
            'effective_from' => \Carbon\CarbonImmutable::parse((string) $version->effective_from)->format('Y-m-d'),
            'effective_to' => \Carbon\CarbonImmutable::parse((string) $version->effective_to)->format('Y-m-d'),
            'retired_at' => $version->retired_at?->toIso8601ZuluString(),
            'retire_reason' => $version->retire_reason,
        ];
    }

    private function lockedItem(int $itemId): PayrollItem
    {
        $item = PayrollItem::query()
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();

        if ($item === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function effectiveFrom(array $data): string
    {
        return isset($data['effective_from'])
            ? BusinessDate::from((string) $data['effective_from'])->value()
            : $this->businessDates->today()->value();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function effectiveTo(array $data, string $effectiveFrom): string
    {
        if (! isset($data['effective_to'])) {
            return BusinessDate::OPEN_END;
        }

        $to = BusinessDate::from((string) $data['effective_to'])->value();

        if ($to < $effectiveFrom) {
            throw new ValidationException([
                'effective_to' => ['The effective_to date must not be before effective_from.'],
            ]);
        }

        return $to;
    }

    private function assertAppendOrder(PayrollItemVersion $current, string $effectiveFrom): void
    {
        $currentFrom = \Carbon\CarbonImmutable::parse((string) $current->effective_from)->format('Y-m-d');
        $currentTo = \Carbon\CarbonImmutable::parse((string) $current->effective_to)->format('Y-m-d');

        if ($effectiveFrom <= $currentFrom) {
            throw new ConflictException('The new payroll-item version must start after the current version.', [
                'reason' => 'PAYROLL_ITEM_VERSION_OVERLAP',
                'current_effective_from' => $currentFrom,
                'effective_from' => $effectiveFrom,
            ]);
        }

        // An open-ended version is superseded by the next version from its own
        // effective_from. A finite range must end before the new version starts.
        if ($currentTo !== BusinessDate::OPEN_END && $effectiveFrom <= $currentTo) {
            throw new ConflictException('The new payroll-item version overlaps the current version.', [
                'reason' => 'PAYROLL_ITEM_VERSION_OVERLAP',
                'current_effective_to' => $currentTo,
                'effective_from' => $effectiveFrom,
            ]);
        }
    }

    public function assertPeriodNotFrozen(string $period, string $reason = 'PAYROLL_RUN_FROZEN'): void
    {
        $period = substr($period, 0, 7);

        $frozen = PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', self::FROZEN_RUN_STATUSES)
            ->exists();

        if ($frozen) {
            throw new ConflictException('Payroll item changes cannot enter a frozen payroll period.', [
                'reason' => $reason,
                'period' => $period,
            ]);
        }
    }

    private function assertNotFrozen(string $businessDate): void
    {
        $this->assertPeriodNotFrozen(substr($businessDate, 0, 7));
    }

    private function assertNoFutureSchemeReference(PayrollItem $item): void
    {
        $today = $this->businessDates->today()->value();

        $referenced = PayrollSchemeItem::query()
            ->where('payroll_item_id', (int) $item->id)
            ->whereExists(function ($query) use ($today): void {
                $query->selectRaw('1')
                    ->from('payroll_schemes')
                    ->whereColumn('payroll_schemes.id', 'payroll_scheme_items.scheme_id')
                    ->where('payroll_schemes.status', 'ACTIVE')
                    ->where('payroll_schemes.valid_from', '>', $today);
            })
            ->exists();

        if ($referenced) {
            throw new ConflictException('The payroll item is referenced by a future payroll scheme.', [
                'reason' => 'PAYROLL_ITEM_IN_USE',
                'item_id' => (int) $item->id,
            ]);
        }
    }
}
