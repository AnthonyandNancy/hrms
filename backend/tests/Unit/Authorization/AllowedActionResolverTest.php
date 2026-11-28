<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Modules\IAM\Authorization\ObjectAllowedActionResolver;
use App\Modules\IAM\Authorization\PageActionCatalog;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

final class AllowedActionResolverTest extends TestCase
{
    public function test_payroll_actions_are_filtered_by_server_state_and_capability(): void
    {
        /** @var Authorizer&MockInterface $authorizer */
        $authorizer = Mockery::mock(Authorizer::class);
        $authorizer->shouldReceive('allows')
            ->andReturnUsing(static function ($principal, string $capability, AnchorRef $anchor): bool {
                return $capability === 'payroll.run.review'
                    && $anchor->type === AnchorType::PAYROLL_RUN
                    && $anchor->id === 77;
            });
        /** @var PeopleDirectory&MockInterface $people */
        $people = Mockery::mock(PeopleDirectory::class);

        $resolver = new ObjectAllowedActionResolver($authorizer, new PageActionCatalog, $people);
        $principal = new TestPrincipal(userId: 10, employmentId: 20);

        $calculated = $resolver->for($principal, 'payroll_run', 77, ['status' => 'CALCULATED'], '2026-11-28');
        $this->assertContains('payroll.run.detail.review', $calculated);
        $this->assertNotContains('payroll.run.detail.approve', $calculated);
        $this->assertNotContains('payroll.run.detail.calculate', $calculated);
        $this->assertNotContains('payroll.run.detail.publish', $calculated);

        $draft = $resolver->for($principal, 'payroll_run', 77, ['status' => 'DRAFT'], '2026-11-28');
        $this->assertSame([], $draft, 'Review must not be available before calculation.');
    }

    public function test_contract_actions_use_the_object_org_anchor_and_state(): void
    {
        /** @var Authorizer&MockInterface $authorizer */
        $authorizer = Mockery::mock(Authorizer::class);
        $authorizer->shouldReceive('allows')
            ->andReturnUsing(static function ($principal, string $capability, AnchorRef $anchor): bool {
                return $capability === 'people.contract.terminate'
                    && $anchor->type === AnchorType::ORG_UNIT
                    && $anchor->id === 9;
            });
        /** @var PeopleDirectory&MockInterface $people */
        $people = Mockery::mock(PeopleDirectory::class);

        $resolver = new ObjectAllowedActionResolver($authorizer, new PageActionCatalog, $people);
        $principal = new TestPrincipal(userId: 10, employmentId: 20);

        $allowed = $resolver->for($principal, 'contract', 501, [
            'status' => 'ACTIVE',
            'subject_employment_id' => 31,
            'org_unit_id' => 9,
        ], '2026-11-28');

        $this->assertContains('people.contract.detail.terminate', $allowed);
        $this->assertNotContains('people.contract.detail.amend', $allowed);

        $terminated = $resolver->for($principal, 'contract', 501, [
            'status' => 'TERMINATED',
            'subject_employment_id' => 31,
            'org_unit_id' => 9,
        ], '2026-11-28');
        $this->assertSame([], $terminated);
    }
}
