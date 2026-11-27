<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\Talent\Services\RecruitmentFunnelService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP adapter for the K9 recruitment funnel.
 *
 * Reads are projected through ProjectionResponder; writes go through
 * CommandRunner so every mutation has bounded idempotency and company context.
 * Capability and anchor checks remain route middleware responsibilities.
 */
final class RecruitmentFunnelController
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly RecruitmentFunnelService $funnel,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    public function board(Request $request): JsonResponse
    {
        return $this->projections->ok($this->funnel->board($this->asOf($request)));
    }

    public function budget(Request $request): JsonResponse
    {
        return $this->projections->ok($this->funnel->budget($this->asOf($request)));
    }

    public function reports(Request $request): JsonResponse
    {
        return $this->projections->ok($this->funnel->reports($this->asOf($request)));
    }

    public function candidates(Request $request): JsonResponse
    {
        return $this->projections->ok($this->funnel->candidates($this->userId(), $this->asOf($request), $request->query()));
    }

    public function importCandidates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requisition_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:32'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
        ]);

        return $this->runCommand(
            $request,
            'recruitment.candidate.import',
            'recruitment.candidate.import',
            $data,
            fn (int $actorUserId): array => $this->funnel->importCandidates($actorUserId, $data),
        );
    }

    public function evaluations(Request $request, int $id): JsonResponse
    {
        return $this->projections->ok($this->funnel->evaluations($this->userId(), $id, $this->asOf($request)));
    }

    public function storeEvaluation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'interview_id' => ['nullable', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'recommendation' => ['required', 'string', 'in:STRONG_YES,YES,NO,STRONG_NO'],
            'strengths' => ['nullable', 'string', 'max:4000'],
            'concerns' => ['nullable', 'string', 'max:4000'],
            'comments' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->runCommand(
            $request,
            'recruitment.evaluation',
            'recruitment.evaluation.manage',
            $data,
            fn (int $actorUserId): array => $this->funnel->createEvaluation($actorUserId, $id, $data),
        );
    }

    public function communications(Request $request, int $id): JsonResponse
    {
        return $this->projections->ok($this->funnel->communications($this->userId(), $id, $this->asOf($request)));
    }

    public function storeCommunication(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:EMAIL,PHONE,SMS,WECHAT,IN_PERSON,OTHER'],
            'direction' => ['required', 'string', 'in:INBOUND,OUTBOUND'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'consent_basis' => ['required', 'string', 'max:64'],
            'sent_at' => ['nullable', 'date'],
        ]);

        return $this->runCommand(
            $request,
            'recruitment.communication',
            'recruitment.communication.manage',
            $data,
            fn (int $actorUserId): array => $this->funnel->createCommunication($actorUserId, $id, $data),
        );
    }

    public function talentPool(Request $request, int $id): JsonResponse
    {
        return $this->projections->ok($this->funnel->talentPool($this->userId(), $id, $this->asOf($request)));
    }

    public function storeTalentPoolTag(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
            'retention_until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->runCommand(
            $request,
            'recruitment.talent-pool',
            'recruitment.talent-pool.manage',
            $data,
            fn (int $actorUserId): array => $this->funnel->createTalentPoolTag($actorUserId, $id, $data),
        );
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'employee_no' => ['required', 'string', 'max:64'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $principal = $this->principal();

        $definition = new CommandDefinition(
            name: 'talent.recruitment.candidate.convert',
            capability: 'recruitment.candidate.convert',
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'talent.candidate.converted',
        );

        $result = $this->commands->run(
            $definition,
            ['candidate_id' => $id, ...$data],
            function () use ($id, $data, $principal): array {
                $body = $this->funnel->convertCandidate($principal->userId(), $id, $data);
                $httpStatus = ($body['status'] ?? null) === 'PENDING_ONBOARDING' ? 202 : 201;

                return [
                    'http_status' => $httpStatus,
                    'body' => $body,
                    'resource_type' => 'candidate_conversion',
                    'resource_id' => (string) ($body['conversion_id'] ?? $body['onboarding_case_id'] ?? ''),
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $businessReplayed = ($result->body['business_replayed'] ?? false) === true;
        $response = match (true) {
            $result->status === 200 || $businessReplayed => ApiResponse::ok($result->body),
            $result->status === 202 => ApiResponse::accepted($result->body),
            default => ApiResponse::created($result->body),
        };

        if ($result->replayed || $businessReplayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure(int): array<string, mixed>  $operation
     */
    private function runCommand(Request $request, string $scope, string $capability, array $payload, \Closure $operation): JsonResponse
    {
        $definition = new CommandDefinition(
            name: 'talent.'.$scope,
            capability: $capability,
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'talent.'.$scope,
        );

        $principal = $this->principal();

        $result = $this->commands->run(
            $definition,
            $payload,
            function () use ($operation, $principal): array {
                return [
                    'http_status' => 201,
                    'body' => $operation($principal->userId()),
                    'resource_type' => 'talent',
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->status === 201 && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function principal(): \App\Modules\IAM\Contracts\Principal
    {
        return app(\App\Shared\Authorization\PrincipalResolver::class)->resolve();
    }

    private function userId(): int
    {
        return $this->principal()->userId();
    }

    private function asOf(Request $request): string
    {
        return $this->businessDates->resolve(
            is_string($request->query('as_of')) ? $request->query('as_of') : null,
        )->value();
    }
}
