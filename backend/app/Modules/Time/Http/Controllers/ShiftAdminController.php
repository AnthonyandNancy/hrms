<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\ShiftAdminService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift administration commands.
 *
 * Reads remain on GET /v1/time/shifts. Writes are new and capability-guarded
 * by time.shift.manage at the route layer; CommandRunner supplies the
 * idempotency ledger, while ShiftAdminService owns append-only versioning and
 * audit evidence.
 */
final class ShiftAdminController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly TimeRequestScope $scope,
        private readonly CommandRunner $commands,
        private readonly ShiftAdminService $shifts,
        private readonly ProjectionResponder $projections,
    ) {}

    /**
     * Current shift versions. Historical rows remain in the list so the UI can
     * show an auditable version trail rather than collapsing history.
     */
    public function index(Request $request): JsonResponse
    {
        $this->principals->resolve();
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);

        $asOf = $this->scope->resolveDate(
            isset($data['as_of']) ? (string) $data['as_of'] : null,
        );

        // The catalogue read is capability-gated at the route, not by an
        // employment relationship: SELF actors may read shifts but never see
        // another employment's schedule through this listing.
        if (! $this->scope->canReadCompanyScope($actor) && ! $actor->hasCapability('time.shift.read')) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($data['page_size'] ?? 20)));
        $active = array_key_exists('active', $data) ? (bool) $data['active'] : null;

        $query = ShiftTemplate::query();

        if ($active === true) {
            $query->whereNull('superseded_at')
                ->where('valid_from', '<=', $asOf)
                ->where('valid_to', '>', $asOf);
        } elseif ($active === false) {
            $query->where(static function ($builder) use ($asOf): void {
                $builder->whereNotNull('superseded_at')
                    ->orWhere('valid_from', '>', $asOf)
                    ->orWhere('valid_to', '<=', $asOf);
            });
        }

        $total = (int) (clone $query)->count();

        /** @var list<ShiftTemplate> $rows */
        $rows = $query
            ->orderBy('code')
            ->orderByDesc('version')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        return $this->projections->ok(new ProjectionResult(
            items: array_map(static fn (ShiftTemplate $row): array => self::payload($row), $rows),
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'shift_templates'],
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:128'],
            'start_local_time' => ['required', 'date_format:H:i'],
            'end_local_time' => ['required', 'date_format:H:i'],
            'crosses_midnight' => ['nullable', 'boolean'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'early_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'standard_hours_decimal' => ['nullable', 'regex:/^\d{1,2}(?:\.\d{1,6})?$/'],
            'valid_from' => ['required', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        $payload = [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'start_local_time' => (string) $data['start_local_time'].':00',
            'end_local_time' => (string) $data['end_local_time'].':00',
            'crosses_midnight' => (bool) ($data['crosses_midnight'] ?? (strcmp((string) $data['end_local_time'], (string) $data['start_local_time']) <= 0)),
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'late_grace_minutes' => (int) ($data['late_grace_minutes'] ?? 0),
            'early_grace_minutes' => (int) ($data['early_grace_minutes'] ?? 0),
            'standard_hours_decimal' => (string) ($data['standard_hours_decimal'] ?? '8.000000'),
            'valid_from' => (string) $data['valid_from'],
            'valid_to' => (string) ($data['valid_to'] ?? '9999-12-31'),
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.shift.create',
                capability: 'time.shift.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.shift.created',
            ),
            $payload,
            fn (): array => $this->writeResult(201, $this->shifts->create($payload, $principal->userId())),
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:128'],
            'start_local_time' => ['sometimes', 'date_format:H:i'],
            'end_local_time' => ['sometimes', 'date_format:H:i'],
            'crosses_midnight' => ['sometimes', 'boolean'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'late_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'early_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'standard_hours_decimal' => ['sometimes', 'regex:/^\d{1,2}(?:\.\d{1,6})?$/'],
            'valid_from' => ['sometimes', new BusinessDateRule],
            'valid_to' => ['sometimes', new BusinessDateRule],
        ]);

        foreach (['start_local_time', 'end_local_time'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (string) $data[$field].':00';
            }
        }

        $payload = [
            'shift_id' => $id,
            ...$data,
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.shift.update',
                capability: 'time.shift.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.shift.versioned',
            ),
            $payload,
            fn (): array => $this->writeResult(201, $this->shifts->update($id, $data, $principal->userId())),
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function disable(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $payload = [
            'shift_id' => $id,
            'reason' => trim((string) $data['reason']),
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.shift.disable',
                capability: 'time.shift.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.shift.disabled',
            ),
            $payload,
            fn (): array => $this->writeResult(200, $this->shifts->disable($id, $payload['reason'], $principal->userId())),
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>, resource_type: string, resource_id: string}
     */
    private function writeResult(int $status, ShiftTemplate $row): array
    {
        return [
            'http_status' => $status,
            'body' => ($status === 201 ? ApiResponse::created(self::payload($row)) : ApiResponse::ok(self::payload($row)))->getData(true),
            'resource_type' => 'shift_template',
            'resource_id' => (string) $row->id,
        ];
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = new JsonResponse($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(ShiftTemplate $row): array
    {
        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'start_local_time' => substr((string) $row->start_local_time, 0, 5),
            'end_local_time' => substr((string) $row->end_local_time, 0, 5),
            'crosses_midnight' => (bool) $row->crosses_midnight,
            'break_minutes' => (int) $row->break_minutes,
            'late_grace_minutes' => (int) $row->late_grace_minutes,
            'early_grace_minutes' => (int) $row->early_grace_minutes,
            'standard_hours_decimal' => (string) $row->standard_hours_decimal,
            'version' => (int) $row->version,
            'valid_from' => $row->valid_from->format('Y-m-d'),
            'valid_to' => $row->valid_to->format('Y-m-d'),
            'superseded_at' => $row->superseded_at !== null ? \Carbon\CarbonImmutable::parse((string) $row->superseded_at)->toIso8601ZuluString() : null,
        ];
    }
}
