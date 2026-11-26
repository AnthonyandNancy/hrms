<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;

/**
 * Atomic employee onboarding.
 *
 * Person, employment, assignment and the first terms version are one business
 * act: a failure in any step must leave no partial employee behind, and a
 * replayed request must not create a second one. Both properties come from
 * CommandRunner (single transaction + idempotency ledger) rather than from
 * hand-rolled bookkeeping here.
 */
final class CreateEmployeeService
{
    public function __construct(
        private readonly PeopleEmploymentWriter $writer,
        private readonly CommandRunner $commands,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{employment_id: int, person_id: int, employee_no: string, assignment_id: int, terms_id: int, replayed: bool}
     */
    public function onboard(array $input, int $actorUserId, string $idempotencyKey): array
    {
        $result = $this->commands->run(
            definition: new CommandDefinition(
                name: 'people.employee.onboard',
                capability: 'people.employee.manage',
                anchor: \App\Shared\Authorization\AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.employee.onboarded',
            ),
            payload: $input,
            operation: function () use ($input, $actorUserId): array {
                $person = $input['person'];
                $employment = $input['employment'];
                $assignment = $input['assignment'];
                $terms = $input['terms'];

                $personId = $this->writer->createPerson([
                    'legal_name' => trim($person['given_name'].' '.$person['family_name']),
                    'id_type' => $person['id_type'] ?? null,
                    'id_number' => $person['national_id'] ?? null,
                    'mobile' => $person['mobile'] ?? null,
                    'email' => $person['email'] ?? null,
                    'birth_date' => $person['birth_date'] ?? null,
                ], $actorUserId);

                $employmentId = $this->writer->createEmployment([
                    'person_id' => $personId,
                    'employee_no' => (string) $employment['employee_no'],
                    'employment_type' => (string) ($employment['employment_type'] ?? 'FULL_TIME'),
                    'status' => 'ACTIVE',
                    'valid_from' => (string) $employment['hire_date'],
                    'hire_date' => (string) $employment['hire_date'],
                    'source_type' => 'ONBOARD',
                ], $actorUserId);

                $assignmentId = $this->writer->createAssignment($employmentId, [
                    'assignment_type' => 'PRIMARY',
                    'is_primary' => true,
                    'org_unit_id' => (int) $assignment['org_unit_id'],
                    'position_id' => isset($assignment['position_id']) ? (int) $assignment['position_id'] : null,
                    'valid_from' => (string) ($assignment['valid_from'] ?? $employment['hire_date']),
                    'source_type' => 'ONBOARD',
                ], $actorUserId);

                $termsId = $this->writer->appendTerms($employmentId, [
                    'effective_from' => (string) ($terms['effective_from'] ?? $employment['hire_date']),
                    'work_location' => (string) ($terms['work_location'] ?? 'HQ'),
                    'work_schedule' => (string) ($terms['work_schedule'] ?? 'STANDARD'),
                    'base_salary_minor' => isset($terms['salary_minor']) ? (int) $terms['salary_minor'] : null,
                    'salary_currency' => (string) ($terms['currency'] ?? 'CNY'),
                    'source_type' => 'ONBOARD',
                ], $actorUserId);

                return [
                    'http_status' => 201,
                    'body' => [
                        'person_id' => $personId,
                        'employment_id' => $employmentId,
                        'employee_no' => (string) $employment['employee_no'],
                        'assignment_id' => $assignmentId,
                        'terms_id' => $termsId,
                    ],
                    'resource_type' => 'employment',
                    'resource_id' => (string) $employmentId,
                ];
            },
            idempotencyKey: $idempotencyKey,
        );

        $body = $result->body;
        $body['replayed'] = $result->replayed;

        return $body;
    }
}
