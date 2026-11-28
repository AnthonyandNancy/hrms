<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Narrow command port for business modules that create or withdraw cases.
 * Callers never depend on Workflow models, CaseService or template internals.
 */
interface CaseCommandPort
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: int, case_no: string, status: string, effect_status: string, template_id: int|null}
     */
    public function open(
        WorkflowPrincipal $principal,
        string $type,
        string $subjectType,
        ?int $subjectId,
        string $title,
        array $payload,
        string $businessDate,
        bool $submit = true,
    ): array;

    /**
     * Record one immutable Workflow Decision and return the resulting case
     * application/effect state. Business modules never flip approval status
     * themselves; this is the only cross-module decision command.
     *
     * @return array{case_id: int, application_status: string, effect_status: string}
     */
    public function decide(
        WorkflowPrincipal $principal,
        int $caseId,
        string $action,
        string $comment,
        ?string $reason = null,
    ): array;

    /**
     * Withdraw a case owned by the same requester. Idempotent when the case is
     * already withdrawn.
     *
     * @return array{id: int, case_no: string, status: string, effect_status: string}
     */
    public function withdraw(
        WorkflowPrincipal $principal,
        int $caseId,
        string $reason,
        ?string $authorizingCapability = null,
    ): array;

    /**
     * Apply the case's declared reverse effect(s) to already-applied effects.
     * Idempotent: terminal REVERSED effects return their existing result.
     *
     * @return array{case_id: int, effect_status: string, reversed: int}
     */
    public function reverseEffect(
        WorkflowPrincipal $principal,
        int $caseId,
        string $reason,
        string $effectiveOn,
        ?string $authorizingCapability = null,
    ): array;

    /**
     * @return array{id: int, case_no: string, title: string, status: string, effect_status: string, requester_user_id: int, type: string, subject_type: string, subject_id: int|null, payload: array<string, mixed>, business_date: string, submitted_at: string|null, current_node: string|null, due_at: string|null}|null
     */
    public function find(int $caseId): ?array;
}
