<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Employment;
use App\Modules\People\Services\EmployeeProfileService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee-profile subresources. Read uses people.employee.read; write uses
 * people.employee.manage. Every employment id is re-scoped through the
 * authorizer before any row is touched.
 */
final class EmployeeProfileController
{
    private const RESOURCES = [
        'education' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'experiences' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'contacts' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'certificates' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'change_requests' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'imports' => ['read' => 'people.employee.manage', 'write' => 'people.employee.manage'],
    ];

    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly EmployeeProfileService $profiles,
    ) {}

    public function index(Request $request, int $employmentId): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['read']);

        $items = $this->profiles->list($resource, $employmentId);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, int $employmentId): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['write']);

        $validated = $this->validate($request, $resource);
        $row = $this->profiles->open($resource, $employmentId, $validated, $principal->userId());

        return ApiResponse::created($row);
    }

    public function update(Request $request, int $employmentId, int $id): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['write']);

        $validated = $this->validate($request, $resource, true);
        $row = $this->profiles->revise($resource, $employmentId, $id, $validated);

        return ApiResponse::ok($row);
    }

    public function submit(Request $request, int $employmentId, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, 'people.employee.manage');

        return ApiResponse::ok($this->profiles->submitChangeRequest(
            $employmentId,
            $id,
            $principal->userId(),
        ));
    }

    private function assertEmploymentVisible(Principal $principal, int $employmentId): void
    {
        $today = $this->businessDates->today()->value();
        $exists = Employment::query()
            ->whereKey($employmentId)
            ->exists();

        if (! $exists) {
            throw new NotFoundOrForbiddenException;
        }

        $visible = $this->authorizer->constrain(
            Employment::query()->whereKey($employmentId),
            $principal,
            'people.employee.read',
            $today,
        )->exists();

        if (! $visible) {
            throw new NotFoundOrForbiddenException;
        }
    }

    private function assertPermits(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @return array{read: string, write: string}
     */
    private function config(string $resource): array
    {
        if (! isset(self::RESOURCES[$resource])) {
            throw new NotFoundOrForbiddenException;
        }

        return self::RESOURCES[$resource];
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Request $request, string $resource, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate(match ($resource) {
            'education' => [
                'school' => [$required, 'string', 'max:160'],
                'degree' => [$required, 'string', 'max:80'],
                'major' => ['nullable', 'string', 'max:160'],
                'start_date' => [$required, 'date_format:Y-m-d'],
                'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'experiences' => [
                'company' => [$required, 'string', 'max:160'],
                'title' => [$required, 'string', 'max:160'],
                'start_date' => [$required, 'date_format:Y-m-d'],
                'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'leave_reason' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'contacts' => [
                'name' => [$required, 'string', 'max:120'],
                'relationship' => [$required, 'string', 'max:64'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:255'],
                'is_emergency' => ['sometimes', 'boolean'],
                'is_primary' => ['sometimes', 'boolean'],
            ],
            'certificates' => [
                'certificate_type' => [$required, 'string', 'max:80'],
                'certificate_no' => ['nullable', 'string', 'max:120'],
                'issuer' => ['nullable', 'string', 'max:160'],
                'issued_on' => ['nullable', 'date_format:Y-m-d'],
                'expires_on' => ['nullable', 'date_format:Y-m-d'],
                'document_id' => ['nullable', 'integer', 'min:1'],
            ],
            'change_requests' => [
                'reason' => [$required, 'string', 'min:3', 'max:500'],
                'diff' => ['sometimes', 'array'],
            ],
            'imports' => [
                'original_name' => [$required, 'string', 'max:255'],
                'total_rows' => ['sometimes', 'integer', 'min:0'],
                'errors' => ['sometimes', 'array'],
            ],
            default => throw new NotFoundOrForbiddenException,
        });
    }
}
