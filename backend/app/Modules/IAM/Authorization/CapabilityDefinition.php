<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;

/**
 * Immutable, code-owned capability metadata. The capabilities table is a
 * synced read model only; no endpoint can edit these semantics.
 */
final class CapabilityDefinition
{
    /**
     * @param  list<string>  $actions
     * @param  list<AnchorType>  $anchorTypes
     */
    public function __construct(
        public readonly string $code,
        public readonly string $resource,
        public readonly array $actions,
        public readonly array $anchorTypes,
        public readonly FieldProfile $fieldProfileMax,
        public readonly string $risk,
        public readonly ?string $sodGroup,
        public readonly string $description,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCatalogRow(): array
    {
        return [
            'code' => $this->code,
            'resource' => $this->resource,
            'actions_json' => $this->actions,
            'anchor_types_json' => array_map(static fn (AnchorType $anchor): string => $anchor->value, $this->anchorTypes),
            'field_profile_max' => $this->fieldProfileMax->value,
            'risk' => $this->risk,
            'sod_group' => $this->sodGroup,
            'description' => $this->description,
        ];
    }
}
