<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\ExportService;
use App\Modules\Reporting\Services\ReportDefinitionCatalog;
use App\Modules\Reporting\Services\ReportService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReportingController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly TenantBusinessDate $businessDates,
        private readonly ReportDefinitionCatalog $catalog,
        private readonly ReportService $reports,
        private readonly ExportService $exports,
    ) {}

    public function definitions(): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->catalog->payloadForTenant($principal->tenantId()));
    }

    public function run(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'definition_code' => ['required', 'string', 'max:80'],
            'filters' => ['nullable', 'array'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null, $principal->tenantId())->value();

        return ApiResponse::ok($this->reports->run(
            $principal,
            strtoupper((string) $data['definition_code']),
            $asOf,
            (array) ($data['filters'] ?? []),
        ));
    }

    public function createExport(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
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

        return ApiResponse::accepted($this->exports->create($principal, $data));
    }

    public function download(Request $request, int $jobId): Response
    {
        $principal = $this->principals->resolve();
        $token = $request->query('token');
        $result = $this->exports->download($principal, $jobId, is_string($token) ? $token : '');

        return response($result['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
