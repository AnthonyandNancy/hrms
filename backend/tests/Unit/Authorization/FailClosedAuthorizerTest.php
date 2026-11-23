<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\DenyAllAuthorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

final class FailClosedAuthorizerTest extends TestCase
{
    public function test_default_authorizer_denies_unknown_capabilities(): void
    {
        $principal = new TestPrincipal(tenantId: 1, userId: 7);
        $authorizer = new DenyAllAuthorizer;

        $this->assertFalse($authorizer->allows($principal, 'people.employee.read', AnchorRef::global(), '2026-11-23'));
        $this->assertInstanceOf(FieldSet::class, $authorizer->fields($principal, 'people.employee.read', new ResourceRef('employment', 1)));
        $this->assertInstanceOf(Builder::class, $authorizer->constrain(\App\Modules\People\Models\Employment::query(), $principal, 'people.employee.read', '2026-11-23'));
        $this->assertInstanceOf(Principal::class, $principal);
    }
}
