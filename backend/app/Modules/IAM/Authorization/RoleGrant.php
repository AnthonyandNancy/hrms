<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;

final class RoleGrant
{
    public function __construct(
        public readonly string $roleCode,
        public readonly string $capabilityCode,
        public readonly AnchorType $anchorType,
        public readonly FieldProfile $fieldProfile,
    ) {}
}
