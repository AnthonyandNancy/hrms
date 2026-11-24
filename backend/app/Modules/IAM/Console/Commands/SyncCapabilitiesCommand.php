<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Capability;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SyncCapabilitiesCommand extends Command
{
    protected $signature = 'iam:sync-capabilities';

    protected $description = 'Sync the code-owned capability catalog and 10 role templates into read models.';

    public function handle(CapabilityCatalog $catalog, Clock $clock): int
    {
        $now = $clock->now();
        $definitions = $catalog->all();
        $codes = array_map(static fn ($definition): string => $definition->code, $definitions);

        $orphaned = AuthorityBinding::query()
            ->where('capability_code', '!=', CapabilityWildcard::WILDCARD)
            ->whereNotIn('capability_code', $codes)
            ->distinct()
            ->pluck('capability_code')
            ->all();

        if ($orphaned !== []) {
            $this->error('Refusing to remove referenced capability codes: '.implode(', ', $orphaned));

            return self::FAILURE;
        }

        DB::transaction(function () use ($definitions, $codes, $now): void {
            foreach ($definitions as $definition) {
                Capability::query()->updateOrCreate(
                    ['code' => $definition->code],
                    [...$definition->toCatalogRow(), 'synced_at' => $now],
                );
            }

            Capability::query()->whereNotIn('code', $codes)->delete();

            foreach (RoleTemplateCatalog::templates() as $template) {
                Role::query()->updateOrCreate(
                    ['code' => $template->code],
                    [
                        'tenant_id' => null,
                        'name' => $template->name,
                        'is_template' => true,
                        'owner' => null,
                        'purpose' => $template->purpose,
                        'expires_at' => null,
                    ],
                );

                RoleCapability::query()->where('role_code', $template->code)->delete();

                foreach ($template->grants as $grant) {
                    RoleCapability::query()->create([
                        'tenant_id' => null,
                        'role_code' => $grant->roleCode,
                        'capability_code' => $grant->capabilityCode,
                        'anchor_type' => $grant->anchorType->value,
                        'field_profile' => $grant->fieldProfile->value,
                    ]);
                }
            }
        });

        $this->info(sprintf('Synced %d capabilities and %d role templates.', count($definitions), count(RoleTemplateCatalog::templates())));

        return self::SUCCESS;
    }
}
