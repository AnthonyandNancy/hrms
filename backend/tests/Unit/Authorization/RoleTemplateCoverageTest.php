<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Shared\Authorization\CapabilityWildcard;
use PHPUnit\Framework\TestCase;

final class RoleTemplateCoverageTest extends TestCase
{
    public function test_super_and_system_admin_hold_exactly_one_wildcard_grant_each(): void
    {
        foreach (['SUPER_ADMIN', 'SYSTEM_ADMIN'] as $code) {
            $template = RoleTemplateCatalog::find($code);
            $this->assertNotNull($template);
            $this->assertCount(1, $template->grants, "{$code} must have exactly one grant.");
            $this->assertSame([CapabilityWildcard::WILDCARD], $template->capabilityCodes(), "{$code} must be a single wildcard grant.");
            $this->assertSame('LEGAL_ENTITY', $template->grants[0]->anchorType->value);
            $this->assertSame('P3_RESTRICTED', $template->grants[0]->fieldProfile->value);
        }
    }

    public function test_only_admin_templates_may_carry_the_wildcard(): void
    {
        foreach (RoleTemplateCatalog::templates() as $template) {
            if (in_array($template->code, ['SUPER_ADMIN', 'SYSTEM_ADMIN'], true)) {
                continue;
            }

            $this->assertNotContains(CapabilityWildcard::WILDCARD, $template->capabilityCodes(), "{$template->code} must not hold the wildcard.");
        }
    }

    public function test_formerly_orphaned_capabilities_are_catalogued_and_wildcard_reachable(): void
    {
        $formerlyOrphaned = [
            'payroll.run.review',
            'payroll.run.approve',
            'payroll.run.publish',
            'payroll.run.close',
            'payroll.run.reopen',
            'payroll.parameters.manage',
            'workflow.case.admin',
            'talent.performance.manage',
            'talent.training.manage',
        ];
        $catalog = new CapabilityCatalog;

        $wildcardHolders = array_values(array_filter(
            RoleTemplateCatalog::templates(),
            static fn ($template): bool => in_array(CapabilityWildcard::WILDCARD, $template->capabilityCodes(), true),
        ));
        $this->assertNotEmpty($wildcardHolders, 'At least one role template must hold the wildcard.');

        $reachableThroughWildcard = [];
        foreach ($wildcardHolders as $template) {
            $reachableThroughWildcard = [...$reachableThroughWildcard, ...$catalog->codes()];
        }

        foreach ($formerlyOrphaned as $code) {
            $this->assertTrue($catalog->has($code), "{$code} must remain in CapabilityCatalog.");
            $this->assertContains(
                $code,
                $reachableThroughWildcard,
                "{$code} must be reachable through a template-held wildcard.",
            );
        }
    }

    public function test_every_catalog_capability_is_issued_by_some_template(): void
    {
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $issued = [...$issued, ...$template->capabilityCodes()];
        }

        $intrinsic = ['iam.session.manage'];

        foreach ((new CapabilityCatalog)->codes() as $code) {
            if (in_array($code, $intrinsic, true)) {
                continue;
            }

            $covered = in_array($code, $issued, true) || in_array(CapabilityWildcard::WILDCARD, $issued, true);
            $this->assertTrue($covered, "No role template issues {$code}.");
        }
    }
}
