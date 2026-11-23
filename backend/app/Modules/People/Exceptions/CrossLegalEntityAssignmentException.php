<?php

declare(strict_types=1);

namespace App\Modules\People\Exceptions;

use App\Shared\Exceptions\ValidationException;

final class CrossLegalEntityAssignmentException extends ValidationException
{
    public function __construct()
    {
        parent::__construct(
            ['org_unit_id' => ['CROSS_LEGAL_ENTITY_ASSIGNMENT: assignment organization must belong to the employment legal entity.']],
            'CROSS_LEGAL_ENTITY_ASSIGNMENT',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return ['reason' => 'CROSS_LEGAL_ENTITY_ASSIGNMENT', 'fields' => parent::details()['fields'] ?? []];
    }
}
