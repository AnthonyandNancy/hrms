# Frontend modules

Domain code lives under `frontend/src/modules/<domain>/`. The domain name matches
the backend module and the router registry prefix (`people`, `timepay`, `talent`,
`platform`). The eventual layout for each domain is:

```text
modules/<domain>/
  pages/
    index.ts          # PAGE_COMPONENTS manifest (route targets)
    <Name>Page.vue    # one page component per page_code
  components/         # module-local business components
  composables/        # module-local state/composition helpers
  api/                # module-local API adapters
  types.ts            # module-local types
```

Shared, business-agnostic components belong in `frontend/src/shared/`, not in a
module. Cross-module imports must use the other module's public `index.ts`, not
its `pages/` directory.

## PAGE_COMPONENTS manifest contract

Every module that owns route targets exports a `PAGE_COMPONENTS` object from
`modules/<domain>/pages/index.ts`:

```ts
export const PAGE_COMPONENTS = {
  'hr/employees/index': () => import('./EmployeeListPage.vue'),
  'hr/departments/index': () => import('./DepartmentTreePage.vue'),
} as const
```

Rules enforced by `node scripts/audit_page_components.mjs`:

- The object is named exactly `PAGE_COMPONENTS`; loader keys are the registry
  `componentKey` values (single- or double-quoted keys are accepted).
- Values are lazy imports of the page's `.vue` file. Paths may be relative to
  the manifest (`./EmployeeListPage.vue`) or absolute from `frontend/src`
  (`@/modules/people/pages/EmployeeListPage.vue`).
- Every registry key owned by the module must appear exactly once, and every
  manifest key must exist in `frontend/src/router/registry.ts` (no stale keys).
- The mapped file must exist and end in `.vue`.
- Two `page_code`s must not map to the same `.vue` file. `DEFAULT_SHARED_ALLOWLIST`
  is intentionally empty: M01 merged MFA and session management into the
  single `/account` page, so no shared page component is permitted.

Base pages (`base/*`) remain in `frontend/src/views/` and are resolved from
`BASE_VIEWS` in `frontend/src/router/pageComponents.ts`. They are part of the
same uniqueness gate but do not need a module manifest.

## Shared shells are not page targets

`components/*PageShell.vue`, workspace shells, layout wrappers, and shared
presentational components (`DataTable`, `PageHeader`, `StatusBadge`,
`MoneyText`, `Timeline`, empty/error/loading states, `Can`) are composition
building blocks. They must never be listed as a `PAGE_COMPONENTS` target.

A page shell may own layout, title, the five states, and retry behaviour. It must
not receive `componentKey`, `pageCode`, `mode`, or similar page-identity props,
and it must not contain business branches that switch behaviour by page. Each
registry page gets its own `<Name>Page.vue` that composes shared shells with
module-specific content.

## Running the gate

From the repository root:

```bash
pnpm page-components:check
# equivalent: node scripts/audit_page_components.mjs
```

`node --test scripts/tests/audit_page_components.test.mjs` unit-tests the parser
and audit rules. The route-coverage audit runs this gate in warning mode during
scaffolding and as a hard failure with `--require-mature`.

## Module boundary rules

`scripts/audit_frontend_modules.mjs` enforces the import direction for
`frontend/src/modules/**/*.{ts,vue}` and the prop contract for shared
components under `frontend/src/shared/**/*.vue` and
`frontend/src/components/**/*.vue`:

- A module page (`modules/<domain>/pages/**`) may depend only on its own module
  (relative imports or `@/modules/<domain>`), the documented shared layers
  (`@/shared`, `@/components`, `@/api`, `@/stores`, `@/router`, `@/utils`,
  `@/types`), Vue packages and Element Plus. This is stricter than the general
  cross-module rule above: pages must not import another module, even through
  its public `index.ts`.
- Module pages must not import `@/views/**`, `axios`, or call the global
  `fetch` directly. API access goes through the module `api/` layer.
- Module `components/` and `api/` files must not import `@/views/**`.
- Shared components must not declare the page-identity props `componentKey`,
  `pageCode`, `mode`, or `shellKey`. Visual-only props such as `variant` and
  `density` are allowed. The sole documented exception is
  `frontend/src/components/ActionGate.vue`'s presentation-only `mode`
  (`default` / `disable`), because Task 9A explicitly requires
  `mode='disable'` for the denied-action hint; it is not a page identity prop.
- The allowlist lives in `BOUNDARY_ALLOWLIST` in the audit script. Every entry
  must name a single file, one prop or import specifier, and a non-empty
  reason. It is empty apart from the ActionGate exception above.

Run the gate from the repository root:

```bash
pnpm modules:check
# equivalent: node scripts/audit_frontend_modules.mjs
# optional fixture root: node scripts/audit_frontend_modules.mjs --root <dir>
```

The CLI prints a JSON report (`ok`, `scanned`, per-layer scan counts,
`allowlistEntries`, `failures`) and exits 1 on a violation. If
`frontend/src/modules` does not exist, it reports `ok: true` with `scanned: 0`
rather than crashing. Tests:

```bash
node --test scripts/tests/audit_frontend_modules.test.mjs
cd frontend && pnpm vitest run tests/unit/moduleBoundaries.spec.ts
```

ESLint mirrors the boundary rule for `src/modules/**/*.{ts,vue}` with
`no-restricted-imports` for `@/views/**` and `@/modules/*/pages/**`. The audit
script is authoritative because it also inspects relative imports and direct
`fetch` calls.
