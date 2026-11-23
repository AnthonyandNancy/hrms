<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Services\MigrationTargetRegistry;
use App\Modules\Platform\Migration\Services\ShadowMigrationTarget;

final class MigrationTargetRegistryFakes
{
    /**
     * @return array<string, InMemoryMigrationTarget>
     */
    public static function install(): array
    {
        /** @var MigrationTargetRegistry $registry */
        $registry = app(MigrationTargetRegistry::class);
        $registry->clear();

        $targets = [];

        foreach (MigrationDomain::cases() as $domain) {
            $target = new InMemoryMigrationTarget($domain);
            $registry->register($target);
            $targets[$domain->value] = $target;
        }

        return $targets;
    }

    /**
     * Add rehearsal shadow targets without replacing real fake ports.
     */
    public static function installShadow(): void
    {
        /** @var MigrationTargetRegistry $registry */
        $registry = app(MigrationTargetRegistry::class);

        foreach (MigrationDomain::cases() as $domain) {
            $registry->register(new ShadowMigrationTarget($domain), shadow: true);
        }
    }
}
