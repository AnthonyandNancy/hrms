<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Contracts;

use App\Modules\Platform\Migration\Dto\CsvDocument;
use App\Modules\Platform\Migration\Dto\ImportValidationResult;
use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

interface CsvDomainImporter
{
    public function domain(): MigrationDomain;

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array;

    /**
     * Validate every row and return normalized migration rows. Implementations
     * must not write target tables.
     */
    public function validate(
        CsvDocument $document,
        ReferenceData $references,
        MappingSet $mappings,
        int $tenantId,
    ): ImportValidationResult;
}
