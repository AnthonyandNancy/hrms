# Volume 2 Task 8 - Menu, permission, scope and field governance report

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Commit: `c255074f0269d7dbef5e1e2595cd60761538faa4`
- Subject: `feat(iam): build menu, permission, scope and field governance pages`

## Delivered

| File | Behaviour |
|---|---|
| `SystemMenusPage.vue` | Tree of menu groups/pages sourced from `PAGE_REGISTRY`; per-company overrides via `POST/PATCH/DELETE /v1/system/menus`; shows page code and required capability; reset-to-default has an inline confirmation; no tenant/legal-entity selector. |
| `SystemPermissionsPage.vue` | Read-only capability catalogue from `GET /v1/capabilities`; grouped by module, searchable, module filter, Chinese capability labels, anchor labels, P0-P3 Chinese profile labels, "used by N pages / N actions" count plus the page names. No write controls. |
| `SystemDataScopesPage.vue` | Lists code-owned templates from `GET /v1/system/data-scopes`; selecting one shows inclusion rules and (for `ORG_UNIT`/`ORG_SUBTREE`) the organization tree from `GET /v1/org-units/tree`; edit button is disabled with a code-owned explanation; no tenant/legal-entity selector. |
| `SystemFieldPoliciesPage.vue` | Groups fields by business object; shows current/declared profile, Chinese P0-P3 labels, affected roles/bindings copy and optional `change_history`; save opens a before/after confirmation covering visible/editable/exportable; 422 field errors map inline; 409 widening is explained in Chinese. |
| `MenuAdminController.php` | Keeps `iam.menu.manage` route guards; rejects unknown page codes with `422 UNKNOWN_PAGE_CODE` and a `page_code` field error; rejects `tenant_id`/`company_id`/`legal_entity_id`. |
| `DataScopeAdminController.php` | Keeps `iam.scope.manage` guards; rejects unknown templates with `422 UNKNOWN_SCOPE_TEMPLATE` and a `template` field error; arbitrary predicates remain rejected; rejects tenant/company/legal-entity parameters. |
| `FieldPolicyAdminController.php` | Keeps `iam.field.manage` guards; rejects unknown field keys with `422 UNKNOWN_FIELD_POLICY` and a `field_key` field error; rejects unknown profiles with `422 UNKNOWN_FIELD_PROFILE` and a `field_profile` field error; rejects tenant/company/legal-entity parameters. |
| `registry.ts` | Appends the real write APIs and `actions: actionsForPage(...)` for the three writable governance pages; `system-permissions` stays `actions: []`. |
| `PermissionResourceCatalog.php` | Mirrors the same `required_apis` write declarations for the three writable pages. |

## Declared write APIs (verified against `artisan route:list` and `docs/openapi/openapi.yaml`)

- Menus: `POST /v1/system/menus`, `PATCH /v1/system/menus/{page_code}`, `DELETE /v1/system/menus/{page_code}`
- Data scope: `PUT /v1/system/data-scopes/{id}`
- Field policy: `PUT /v1/system/field-policies/{field_key}`
- Permissions: no write APIs.

## Verification

| Gate | Result |
|---|---|
| `pnpm exec vitest run tests/unit/systemGovernancePages.spec.ts` | PASS: 1 file, 4 tests |
| `pnpm typecheck` | PASS (`vue-tsc -b`, no output) |
| `pnpm lint` | PASS (`eslint . --max-warnings=0`, no output) |
| `phpunit --filter='MenuAdmin\|DataScopeAdmin\|FieldPolicyAdmin\|GovernanceAdmin'` | PASS: 5 tests, 39 assertions |
| Extra focused PHPUnit (GovernanceAdminValidation + MenuOverride + ScopePolicyWrite + FieldPolicyEscalation) | PASS: 13 tests, 100 assertions |
| Pint on the changed backend controllers/catalog/test | PASS: 5 files |
| Full `vendor/bin/pint --test` | FAIL on files owned by other agents (`SessionManager.php`, `AuthorizationSnapshot.php`, `AdminProtectionTest.php`, `AuthorizationConsistencyTest.php`) |

## Concerns / API gaps

1. **No permission-catalogue endpoint.** The read-only page consumes `GET /v1/capabilities`, which is gated by `iam.role.manage`, not `iam.permission.read`. A user holding only `iam.permission.read` can open `system-permissions` but the API returns 403. This is a backend contract gap for a parallel task.
2. **No field-policy history endpoint.** The page renders `change_history` when returned, but the current `GET /v1/system/field-policies` does not return it, so the UI truthfully shows "暂无变更历史（接口未返回）".
3. **No affected-subject endpoint.** "Who it affects" is described at the binding/role class level; there is no per-field affected-user API to show concrete counts/names.
4. **Data scope templates are read-only in this task.** The page selects a template and shows its rules, but there is no `ORG_UNIT` picker write flow because the template catalogue is code-owned; `PUT /v1/system/data-scopes/{id}` only relabels/activates a template, which the page deliberately does not expose.
5. `platformModulePages.spec.ts` still expects these four pages to be generic `PlatformWorkspace` pages and fails on that assumption; the dedicated implementations are intentional and the new spec covers them. The spec file is outside my allowed list, so I did not edit it.
6. Full typecheck/lint were re-run after other agents' concurrent fixes and pass; full Pint still fails only on parallel-owned files.
