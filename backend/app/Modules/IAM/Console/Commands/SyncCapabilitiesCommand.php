<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RolePageAssignmentCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Capability;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\RolePage;
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

        $orphaned = array_values(array_unique([
            ...AuthorityBinding::query()
                ->where('capability_code', '!=', CapabilityWildcard::WILDCARD)
                ->whereNotIn('capability_code', $codes)
                ->distinct()
                ->pluck('capability_code')
                ->all(),
            ...RoleCapability::query()
                ->where('capability_code', '!=', CapabilityWildcard::WILDCARD)
                ->whereNotIn('capability_code', $codes)
                ->distinct()
                ->pluck('capability_code')
                ->all(),
        ]));

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
                        'role_code' => $grant->roleCode,
                        'capability_code' => $grant->capabilityCode,
                        'anchor_type' => $grant->anchorType->value,
                        'anchor_id' => null,
                        'field_profile' => $grant->fieldProfile->value,
                    ]);
                }
            }
        });

        $rolePages = new RolePageAssignmentCatalog;
        $pageCount = 0;

        $templateCodes = array_map(
            static fn ($template): string => $template->code,
            RoleTemplateCatalog::templates(),
        );

        DB::transaction(function () use ($rolePages, $templateCodes, $now, &$pageCount): void {
            // Template page grants are code-owned and may be replaced, but a
            // catalog sync must never delete custom-role assignments.
            RolePage::query()->whereIn('role_code', $templateCodes)->delete();

            foreach (RoleTemplateCatalog::templates() as $template) {
                foreach ($rolePages->pagesForRole($template->code) as $page) {
                    RolePage::query()->create([
                        'role_code' => $template->code,
                        'page_code' => (string) $page['page_code'],
                        'created_at' => $now,
                    ]);
                    $pageCount++;
                }
            }
        });

        $this->info(sprintf('Synced %d capabilities, %d role templates and %d role page grants.', count($definitions), count(RoleTemplateCatalog::templates()), $pageCount));

        return self::SUCCESS;
    }
}
