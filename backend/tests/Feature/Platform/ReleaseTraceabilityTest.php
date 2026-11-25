<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;

final class ReleaseTraceabilityTest extends TestCase
{
    public function test_meta_exposes_release_commit_build_time_and_api_version(): void
    {
        config()->set('app.release.commit', 'abc1234');
        config()->set('app.release.build_time', '2026-11-25T00:00:00Z');
        config()->set('app.release.spa_build_sha', 'abc1234');

        $this->getJson('/v1/meta')
            ->assertOk()
            ->assertJsonPath('data.commit', 'abc1234')
            ->assertJsonPath('data.build_time', '2026-11-25T00:00:00Z')
            ->assertJsonPath('data.spa_build_sha', 'abc1234')
            ->assertJsonPath('data.api_version', 'v1');
    }

    public function test_meta_falls_back_to_unknown_instead_of_fabricating_a_sha(): void
    {
        config()->set('app.release.commit', null);
        config()->set('app.release.build_time', null);
        config()->set('app.release.spa_build_sha', null);

        $this->getJson('/v1/meta')
            ->assertOk()
            ->assertJsonPath('data.commit', 'unknown')
            ->assertJsonPath('data.build_time', 'unknown')
            ->assertJsonPath('data.spa_build_sha', 'unknown');
    }
}
