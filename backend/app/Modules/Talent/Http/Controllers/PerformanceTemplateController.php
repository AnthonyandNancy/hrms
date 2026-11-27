<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Talent\Services\PerformanceTemplateService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PerformanceTemplateController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly PerformanceTemplateService $templates,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.read');
        $items = $this->templates->list();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:64'],
            'sections.*.weight' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $definition = new CommandDefinition(
            name: 'talent.performance.template.publish',
            capability: 'performance.cycle.manage',
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'talent.performance.template.publish',
        );

        $result = $this->commands->run(
            $definition,
            $data,
            fn (): array => [
                'http_status' => 201,
                'body' => $this->templates->publish($principal->userId(), $data),
                'resource_type' => 'talent.performance_template',
            ],
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->replayed ? ApiResponse::ok($result->body) : ApiResponse::created($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::company(), $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
