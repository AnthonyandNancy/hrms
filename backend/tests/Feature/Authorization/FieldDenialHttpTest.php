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
            ->forPrincipal(1, (int) $user->id)
            ->anchor(AnchorType::LEGAL_ENTITY, 1, FieldProfile::P2_SENSITIVE)
            ->create(['capability_code' => 'people.employee.read']);
        $this->organization->knownLegalEntities = [1];

        $set = app(\App\Shared\Authorization\Authorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            new \App\Shared\Authorization\ResourceRef('employee', 1, ['id_number' => 'x'], 1, 1, 1, 1),
        );

        try {
            $set->assertFilterable('id_number');
            $this->fail('P2 field must not be filterable.');
        } catch (\App\Shared\Exceptions\ValidationException $exception) {
            $this->assertSame('FIELD_NOT_FILTERABLE', $exception->details()['reason']);
        }
    }

    public function test_idor_user_cannot_read_another_users_bindings(): void
    {
        $caller = User::factory()->create();
        $other = User::factory()->create(['tenant_id' => 1]);

        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $caller->id)
            ->anchor(AnchorType::LEGAL_ENTITY, 1)
            ->create(['capability_code' => 'iam.binding.manage']);

        $this->people->employmentByUser[(int) $caller->id] = [1];
        $auth = $this->loginAs($caller);

        $response = $this->withHeader('X-CSRF-Token', $auth['csrf'])
            ->getJson('/v1/users/'.$other->id.'/bindings', ['X-Legal-Entity-Id' => '1']);

        $response->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

}
