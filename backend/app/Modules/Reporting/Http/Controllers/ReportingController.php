<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Services\ExportService;
use App\Modules\Reporting\Services\ReportDefinitionCatalog;
use App\Modules\Reporting\Services\ReportService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReportingController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly CompanyBusinessDate $businessDates,
        private readonly \App\Shared\Authorization\Authorizer $authorizer,
        private readonly AuthorityBindingRepository $bindings,
        private readonly ReportDefinitionCatalog $catalog,
        private readonly ReportService $reports,
        private readonly ExportService $exports,
        private readonly \App\Shared\Commands\CommandRunner $commands,
        private readonly \App\Shared\Projections\ProjectionResponder $projections,
    ) {}

    public function definitions(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $all = array_map(
            fn (array $definition): array => $this->businessDefinition($definition, $principal),
            $this->catalog->allActive(),
        );
        $total = count($all);
        $items = array_slice($all, max(0, ($page - 1) * $pageSize), $pageSize);

        return $this->projections->ok(new \App\Shared\Projections\ProjectionResult(
            items: array_values($items),
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today()->value(),
            freshness: \App\Shared\Projections\ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($all, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: null,
        ));
    }

    public function createDefinition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'source_projection' => ['required', 'string', 'max:80'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'max:80'],
            'filters' => ['nullable', 'array'],
        ]);
        $principal = $this->principals->resolve();
        $definition = new \App\Shared\Commands\CommandDefinition(
            name: 'reporting.definition.create',
            capability: 'reporting.definition.manage',
            anchor: \App\Shared\Authorization\AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'reporting.definition.created',
        );
        $result = $this->commands->run(
            $definition,
            $data,
            function () use ($data, $principal): array {
                $created = $this->catalog->createCustom($principal->userId(), $data);

                return [
                    'http_status' => 201,
                    'body' => $created,
                    'resource_type' => 'report_definition',
                    'resource_id' => (string) $created['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );
        $response = $result->status === 201 ? ApiResponse::created($result->body) : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function run(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'definition_code' => ['required', 'string', 'max:80'],
            'filters' => ['nullable', 'array'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null)->value();

        return ApiResponse::ok($this->reports->run(
            $principal,
            strtoupper((string) $data['definition_code']),
            $asOf,
            (array) ($data['filters'] ?? []),
        ));
    }

    public function createExport(Request $request): JsonResponse
    {
        $this->principals->resolve();
        $data = $request->validate([
            'reportCode' => ['required_without:report_code', 'string', 'max:80'],
            'report_code' => ['required_without:reportCode', 'string', 'max:80'],
            'purpose' => ['required', 'string', 'max:40'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'max:80'],
            'filters' => ['nullable', 'array'],
            'asOf' => ['nullable', new BusinessDateRule],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $definition = new \App\Shared\Commands\CommandDefinition(
            name: 'reporting.export.create',
            capability: 'reporting.export',
            anchor: \App\Shared\Authorization\AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'reporting.export.requested',
        );
        $commandPayload = [
            'reportCode' => (string) ($data['reportCode'] ?? $data['report_code'] ?? ''),
            'purpose' => (string) $data['purpose'],
            'fields' => (array) $data['fields'],
            'filters' => (array) ($data['filters'] ?? []),
            'asOf' => $data['asOf'] ?? $data['as_of'] ?? null,
        ];
        $result = $this->commands->run(
            $definition,
            $commandPayload,
            function () use ($data): array {
                // Re-resolve at execution time so a replayed idempotent
                // command always uses the current principal context.
                $created = $this->exports->create($this->principals->resolve(), $data);

                return [
                    'http_status' => 202,
                    'body' => $this->exportResponse($created),
                    'resource_type' => 'export_job',
                    'resource_id' => (string) $created['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );
        $response = ApiResponse::accepted($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function exports(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->projections->ok($this->exports->history(
            $principal,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        ));
    }

    public function download(Request $request, int $jobId): Response
    {
        $principal = $this->principals->resolve();
        $token = $request->query('token');
        $signed = \Illuminate\Support\Facades\URL::hasValidSignature($request);
        $result = $this->exports->download($principal, $jobId, is_string($token) ? $token : '', $signed);

        return response($result['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Business-facing report definition. Internal projection names stay on the
     * server; the UI groups by the localized business domain instead.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function businessDefinition(array $definition, Principal $principal): array
    {
        unset($definition['sourceProjection'], $definition['source_projection']);

        $businessDate = $this->businessDates->today()->value();
        $canExport = $this->authorizer->permits($principal, 'reporting.export', $businessDate);
        $exportProfile = $canExport ? $this->effectiveExportProfile($principal, $businessDate) : null;

        $definition['fields'] = array_map(static function (mixed $field) use ($exportProfile): array {
            /** @var array<string, mixed> $field */
            $field = (array) $field;
            $profile = FieldProfile::tryFrom((string) ($field['profile'] ?? '')) ?? FieldProfile::P3_RESTRICTED;
            $exportable = (bool) ($field['exportable'] ?? false)
                && ! $profile->isHidden()
                && $exportProfile !== null
                && $profile->rank() <= $exportProfile->rank();

            return [
                'key' => (string) ($field['key'] ?? ''),
                'label' => (string) ($field['label'] ?? ''),
                'profile' => $profile->value,
                'exportable' => $exportable,
            ];
        }, (array) ($definition['fields'] ?? []));
        $definition['filters'] = array_map(static function (mixed $filter): array {
            /** @var array<string, mixed> $filter */
            $filter = (array) $filter;

            return [
                'key' => (string) ($filter['key'] ?? ''),
                'label' => (string) ($filter['label'] ?? ''),
                'type' => (string) ($filter['type'] ?? 'SELECT'),
                'options' => array_values(array_map('strval', (array) ($filter['options'] ?? []))),
            ];
        }, (array) ($definition['filters'] ?? []));

        return $definition;
    }

    private function effectiveExportProfile(Principal $principal, string $asOf): ?FieldProfile
    {
        $effective = null;

        foreach ($this->bindings->effectiveBindings(
            $principal->userId(),
            'reporting.export',
            $principal->authVersion(),
            $asOf,
        ) as $grant) {
            if ($effective === null || $grant->fieldProfile->rank() > $effective->rank()) {
                $effective = $grant->fieldProfile;
            }
        }

        return $effective;
    }

    /**
     * Creation returns the one-time raw token plus the short-lived signed URL.
     * History and job payloads expose only the signed URL.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function exportResponse(array $body): array
    {
        $downloadUrl = $body['downloadUrl'] ?? null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            $downloadUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'reporting.exports.download',
                \Carbon\CarbonImmutable::now('UTC')->addHours(24),
                ['jobId' => (int) ($body['id'] ?? 0)],
            );
        }

        return [
            ...$body,
            'id' => (string) ($body['id'] ?? ''),
            'downloadUrl' => $downloadUrl,
        ];
    }
}
