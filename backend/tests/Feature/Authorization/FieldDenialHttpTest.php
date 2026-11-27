<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\AuthenticatesRequests;

final class FieldDenialHttpTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_hidden_field_filter_returns_422_and_never_queries_it(): void
    {
        $user = User::factory()->create();
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE)
            ->create(['capability_code' => 'people.employee.read']);

        $set = app(\App\Shared\Authorization\Authorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            new \App\Shared\Authorization\ResourceRef('employee', 1, ['id_number' => 'x'], employmentId: 1, orgUnitId: 1),
        );

        try {
            $set->assertFilterable('id_number');
            $this->fail('P2 field must not be filterable.');
        } catch (\App\Shared\Exceptions\ValidationException $exception) {
            $this->assertSame('FIELD_NOT_FILTERABLE', $exception->details()['reason']);
        }
    }

    public function test_user_without_binding_manage_cannot_read_another_users_bindings(): void
    {
        $caller = User::factory()->create();
        $other = User::factory()->create();

        AuthorityBinding::factory()
            ->forPrincipal((int) $caller->id)
            ->anchor(AnchorType::COMPANY, null)
            ->create(['capability_code' => 'iam.user.read']);

        $this->people->employmentByUser[(int) $caller->id] = [1];
        $auth = $this->loginAs($caller);

        $this->withHeader('X-CSRF-Token', $auth['csrf'])
            ->getJson('/v1/users/'.$other->id.'/bindings')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('data.capability', 'iam.binding.manage');
    }
}
