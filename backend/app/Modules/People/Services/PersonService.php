<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\Person;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Observability\AuditRecorder;

final class PersonService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PersonPrivacy $privacy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(array $input, ?int $actorUserId): Person
    {
        $idType = isset($input['id_type']) ? mb_strtoupper((string) $input['id_type']) : null;
        $idNumber = isset($input['id_number']) ? (string) $input['id_number'] : null;
        $identityHash = ($idType !== null && $idNumber !== null && $idNumber !== '')
            ? $this->privacy->hashIdentity($idType, $idNumber)
            : null;

        if ($identityHash !== null && Person::query()->where('id_type', $idType)->where('id_number_hash', $identityHash)->exists()) {
            throw new ConflictException(
                'A person with the same identity document already exists.',
                ['reason' => 'PERSON_IDENTITY_DUPLICATE'],
            );
        }

        $person = Person::query()->create([
            'legal_name' => (string) $input['legal_name'],
            'preferred_name' => $input['preferred_name'] ?? null,
            'id_type' => $idType,
            'id_number_ciphertext' => $idNumber !== null && $idNumber !== '' ? $identityHash === null ? null : $idNumber : null,
            'id_number_hash' => $identityHash,
            'birth_date' => $input['birth_date'] ?? null,
            'gender' => $input['gender'] ?? null,
            'nationality' => $input['nationality'] ?? null,
            'mobile_ciphertext' => isset($input['mobile']) ? (string) $input['mobile'] : null,
            'email' => $input['email'] ?? null,
        ]);

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.person.created',
            aggregateType: 'person',
            aggregateId: $person->id,
            after: [
                'person_id' => (int) $person->id,
                'id_type' => $idType,
                'has_identity_hash' => $identityHash !== null,
            ],
        );

        return $person;
    }
}
