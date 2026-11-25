<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Models\ReportSubscription;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\AccessSubjectStateDirectory;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Report subscription lifecycle. A subscription is metadata only: every run
 * re-authorizes the recipient through the live authorization engine before a
 * report projection may be built.
 */
final class ReportSubscriptionService
{
    /** @var list<string> */
    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly Authorizer $authorizer,
        private readonly AccessSubjectStateDirectory $subjects,
        private readonly AuditRecorder $audit,
        private readonly TenantBusinessDate $businessDates,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(int $tenantId, int $actorUserId, array $input): array
    {
        $definitionCode = strtoupper(trim((string) ($input['definition_code'] ?? '')));
        $definition = $this->reports->definition($tenantId, $definitionCode);
        $frequency = strtoupper((string) ($input['frequency'] ?? ''));
        $recipientUserId = (int) ($input['recipient_user_id'] ?? 0);
        $filters = $this->approvedFilters($definition, $input['filters'] ?? []);

        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new ValidationException(['frequency' => ['Frequency must be DAILY, WEEKLY, MONTHLY or QUARTERLY.']], 'The given data was invalid.', ['reason' => 'INVALID_SUBSCRIPTION_FREQUENCY']);
        }

        if ($recipientUserId <= 0) {
            throw new ValidationException(['recipient_user_id' => ['A valid recipient_user_id is required.']], 'The given data was invalid.', ['reason' => 'RECIPIENT_REQUIRED']);
        }

        if (! $this->recipientCanRead($tenantId, $recipientUserId)) {
            throw new ValidationException(['recipient_user_id' => ['The recipient must currently hold reporting.report.read.']], 'The given data was invalid.', ['reason' => 'RECIPIENT_NOT_AUTHORIZED']);
        }

        $now = $this->clock->now();

        $subscription = ReportSubscription::query()->create([
            'tenant_id' => $tenantId,
            'owner_user_id' => $actorUserId,
            'recipient_user_id' => $recipientUserId,
            'definition_code' => (string) $definition['code'],
            'frequency' => $frequency,
            'filters_json' => $filters,
            'active' => true,
            'last_run_at' => null,
            'next_run_at' => $this->nextRunAt($now, $frequency),
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            action: 'reporting.subscription.created',
            aggregateType: 'report_subscription',
            aggregateId: (int) $subscription->id,
            after: $this->payload($subscription),
        );

        return $this->payload($subscription);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $actorUserId, int $subscriptionId, array $input): array
    {
        $subscription = $this->owned($tenantId, $actorUserId, $subscriptionId);
        $before = $this->payload($subscription);
        $changes = [];
        $action = null;

        if (array_key_exists('active', $input)) {
            $active = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($active === null) {
                throw new ValidationException(['active' => ['active must be a boolean.']], 'The given data was invalid.', ['reason' => 'INVALID_SUBSCRIPTION_STATE']);
            }

            if ((bool) $subscription->active !== $active) {
                $action = $active ? 'reporting.subscription.resumed' : 'reporting.subscription.paused';
            }

            $changes['active'] = $active;
        }

        if (array_key_exists('definition_code', $input)) {
            $definition = $this->reports->definition($tenantId, strtoupper((string) $input['definition_code']));
            $changes['definition_code'] = (string) $definition['code'];
            $changes['filters_json'] = $this->approvedFilters($definition, $input['filters'] ?? $subscription->filters_json ?? []);
        }

        if (array_key_exists('recipient_user_id', $input)) {
            $recipientUserId = (int) $input['recipient_user_id'];

            if ($recipientUserId <= 0 || ! $this->recipientCanRead($tenantId, $recipientUserId)) {
                throw new ValidationException(['recipient_user_id' => ['The recipient must currently hold reporting.report.read.']], 'The given data was invalid.', ['reason' => 'RECIPIENT_NOT_AUTHORIZED']);
            }

            $changes['recipient_user_id'] = $recipientUserId;
        }

        if (array_key_exists('frequency', $input)) {
            $frequency = strtoupper((string) $input['frequency']);

            if (! in_array($frequency, self::FREQUENCIES, true)) {
                throw new ValidationException(['frequency' => ['Frequency must be DAILY, WEEKLY, MONTHLY or QUARTERLY.']], 'The given data was invalid.', ['reason' => 'INVALID_SUBSCRIPTION_FREQUENCY']);
            }

            $changes['frequency'] = $frequency;
            $changes['next_run_at'] = $this->nextRunAt($this->clock->now(), $frequency);
        }

        if (array_key_exists('filters', $input) && ! array_key_exists('filters_json', $changes)) {
            $definition = $this->reports->definition($tenantId, (string) $subscription->definition_code);
            $changes['filters_json'] = $this->approvedFilters($definition, $input['filters']);
        }

        if ($changes === []) {
            throw new ValidationException(['active' => ['At least one subscription field must be supplied.']], 'The given data was invalid.', ['reason' => 'NO_SUBSCRIPTION_CHANGES']);
        }

        DB::transaction(function () use ($subscription, $changes): void {
            $subscription->forceFill($changes)->save();
        });
        $subscription->refresh();

        if ($action !== null) {
            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: $action,
                aggregateType: 'report_subscription',
                aggregateId: (int) $subscription->id,
                before: $before,
                after: $this->payload($subscription),
            );
        }

        return $this->payload($subscription);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $tenantId, int $actorUserId, int $subscriptionId): array
    {
        $subscription = $this->owned($tenantId, $actorUserId, $subscriptionId);
        $before = $this->payload($subscription);
        $subscription->delete();

        $this->audit->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            action: 'reporting.subscription.deleted',
            aggregateType: 'report_subscription',
            aggregateId: $subscriptionId,
            before: $before,
        );

        return ['id' => $subscriptionId, 'deleted' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForId(int $tenantId, int $actorUserId, int $subscriptionId): array
    {
        return $this->payload($this->owned($tenantId, $actorUserId, $subscriptionId));
    }

    public function listing(int $tenantId, int $actorUserId, int $page, int $pageSize): ProjectionResult
    {
        $query = ReportSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_user_id', $actorUserId)
            ->orderByDesc('id');

        $total = (int) $query->clone()->count();
        $items = $query->forPage($page, $pageSize)
            ->get()
            ->map(fn (ReportSubscription $subscription): array => $this->payload($subscription))
            ->all();

        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today($tenantId)->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: null,
        );
    }

    /**
     * Re-authorize the recipient immediately before a run. The caller must own
     * the subscription; a denied recipient produces audit evidence and no run.
     */
    public function authorizeForExecution(int $actorUserId, int $subscriptionId, ?int $tenantId = null): bool
    {
        $subscription = ReportSubscription::query()
            ->whereKey($subscriptionId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if ($subscription === null
            || (int) $subscription->owner_user_id !== $actorUserId
            || ! (bool) $subscription->active) {
            return false;
        }

        $authorized = $this->recipientCanRead((int) $subscription->tenant_id, (int) $subscription->recipient_user_id);

        if (! $authorized) {
            $this->audit->record(
                tenantId: (int) $subscription->tenant_id,
                actorUserId: $actorUserId,
                action: 'reporting.subscription.execution_denied',
                aggregateType: 'report_subscription',
                aggregateId: (int) $subscription->id,
                metadata: ['reason' => 'RECIPIENT_NOT_AUTHORIZED'],
            );
        }

        return $authorized;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function approvedFilters(array $definition, mixed $filters): array
    {
        if ($filters === null || $filters === []) {
            return [];
        }

        if (! is_array($filters) || array_is_list($filters)) {
            throw new ValidationException(['filters' => ['Filters must be an object keyed by approved filter names.']], 'The given data was invalid.', ['reason' => 'INVALID_REPORT_FILTERS']);
        }

        $allowed = [];

        foreach ((array) ($definition['filters'] ?? []) as $filter) {
            /** @var array<string, mixed> $filter */
            $allowed[(string) ($filter['key'] ?? '')] = true;
        }

        $approved = [];

        foreach ($filters as $key => $value) {
            $name = (string) $key;

            if (! isset($allowed[$name])) {
                throw new ValidationException(['filters' => ["Filter {$name} is not approved for this definition."]], 'The given data was invalid.', ['reason' => 'UNKNOWN_REPORT_FILTER']);
            }

            if (is_array($value) || is_object($value)) {
                throw new ValidationException(['filters' => ["Filter {$name} must be a scalar value."]], 'The given data was invalid.', ['reason' => 'INVALID_REPORT_FILTER_VALUE']);
            }

            $approved[$name] = $value;
        }

        return $approved;
    }

    private function recipientCanRead(int $tenantId, int $recipientUserId): bool
    {
        $authVersion = $this->subjects->currentAuthVersion($tenantId, $recipientUserId);

        if ($authVersion === null || ! $this->subjects->isEnabled($tenantId, $recipientUserId)) {
            return false;
        }

        try {
            return $this->authorizer->permits(
                new ReportSubscriptionPrincipal($tenantId, $recipientUserId, $authVersion),
                'reporting.report.read',
                $this->businessDates->today($tenantId)->value(),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function owned(int $tenantId, int $actorUserId, int $subscriptionId): ReportSubscription
    {
        $subscription = ReportSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_user_id', $actorUserId)
            ->whereKey($subscriptionId)
            ->first();

        if ($subscription === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $subscription;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ReportSubscription $subscription): array
    {
        return [
            'id' => (int) $subscription->id,
            'definitionCode' => (string) $subscription->definition_code,
            'recipientUserId' => (int) $subscription->recipient_user_id,
            'frequency' => (string) $subscription->frequency,
            'filters' => (array) ($subscription->filters_json ?? []),
            'active' => (bool) $subscription->active,
            'lastRunAt' => $subscription->last_run_at instanceof \Carbon\CarbonInterface
                ? $subscription->last_run_at->toIso8601ZuluString()
                : null,
            'nextRunAt' => $subscription->next_run_at instanceof \Carbon\CarbonInterface
                ? $subscription->next_run_at->toIso8601ZuluString()
                : null,
            'createdAt' => $subscription->created_at?->toIso8601ZuluString(),
        ];
    }

    private function nextRunAt(CarbonImmutable $now, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'DAILY' => $now->addDay(),
            'WEEKLY' => $now->addWeek(),
            'MONTHLY' => $now->addMonth(),
            'QUARTERLY' => $now->addMonths(3),
            default => $now->addMonth(),
        };
    }
}
