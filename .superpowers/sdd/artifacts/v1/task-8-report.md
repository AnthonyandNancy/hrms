# Task 8 Report — 产品级门禁测试先行

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Implementation HEAD before commit: `7e3553f`
- Commit subject: `test(gates): add page, primitive and copy gates with baseline inventory`
- Scope: created the three gate scripts, their three fixture tests, the three package scripts, and the gate baseline document. No existing page, registry entry, or gate rule was changed.

## Delivered

| File | Change |
|---|---|
| `scripts/audit_page_model.mjs` | Pure `auditPageModel({pages,tabs})` + real-repo CLI over the registry and module Vue nav/tab markup. |
| `scripts/audit_ui_primitives.mjs` | Pure `auditUiPrimitives({files})` + CLI recursively scanning `frontend/src/modules/**/*.vue` with file/line failures. |
| `scripts/audit_user_copy.mjs` | Pure `auditUserCopy({files})` + CLI recursively scanning module Vue files with matched token and line. |
| `scripts/tests/audit_page_model.test.mjs` | Brief counterexample fixture. |
| `scripts/tests/audit_ui_primitives.test.mjs` | Brief counterexample fixture. |
| `scripts/tests/audit_user_copy.test.mjs` | Brief counterexample fixture. |
| `package.json` | Added `page-model:check`, `ui-primitives:check`, `copy:check`. |
| `docs/audit/2026-11-26-v2-gate-baseline.md` | Actual CLI JSON, exit codes, counts, every failure assigned to a Volume 2/3 task (plus shell retirement ownership). |

## TDD evidence

### RED

Command (repository root):

```text
node --test scripts/tests/audit_page_model.test.mjs scripts/tests/audit_ui_primitives.test.mjs scripts/tests/audit_user_copy.test.mjs
```

Result: exit 1. All three files failed with `ERR_MODULE_NOT_FOUND` for the three not-yet-created gate modules. This is the expected RED reason.

A second RED was observed during implementation: after the copy gate was written with only a rule label, the fixture assertion `/GET \/v1\//` failed because the failure text did not include the matched token. The gate was corrected to include the matched token (`API path 'GET /v1/'`), then the fixture passed. This proves the fixture protects the required diagnostic content rather than only the exit status.

### GREEN (final exact command)

```text
node --test scripts/tests/audit_page_model.test.mjs scripts/tests/audit_ui_primitives.test.mjs scripts/tests/audit_user_copy.test.mjs
✔ rejects cross-module tabs and action-like pages
✔ rejects business pages with raw table/input/select
✔ rejects technical copy and English enums in user-facing templates
ℹ tests 3
ℹ pass 3
ℹ fail 0
```

Exit 0. Full scripts suite also run: `node --test scripts/tests/*.test.mjs` — 51 tests, 51 pass, exit 0.

## Real-repository gate runs (baseline, NOT green)

Baseline capture HEAD: `7e3553f`. Each command was run twice; both outputs diffed clean (deterministic).

| Command | Exit | Failures | Breakdown |
|---|---:|---:|---|
| `node scripts/audit_page_model.mjs` | 1 | 12 | 7 action-like paths + 5 cross-module tabs |
| `node scripts/audit_ui_primitives.mjs` | 1 | 11 | SystemRoles 3, SystemUsers 3, TalentForm 3, TalentWorkspace 2 |
| `node scripts/audit_user_copy.mjs` | 1 | 12 | API path 4, English enum 5, Wave 1, field profile 2 |

Package script wiring verified: `pnpm page-model:check`, `pnpm ui-primitives:check`, `pnpm copy:check` each exit 1 with the same reports. The real-repo gates are **not claimed green**. They are deliberately left failing and no allowlist was added.

## Key implementation decisions

1. **Pure functions match the brief exactly.** `auditPageModel({ pages, tabs })`, `auditUiPrimitives({ files })`, and `auditUserCopy({ files })` are exported and are the only inputs the fixture tests use.
2. **CLIs use one JSON object.** Each prints exactly one `{ok, failures}` JSON document to stdout and sets exit code 1 when `ok` is false. Parse failures are converted to the same shape, so consumers never receive a stack trace as the report.
3. **Line numbers.** `ui-primitives` and `copy` include the first matching line; this satisfies the design requirement for actionable file/line failures.
4. **Registry parsing reuse.** `audit_page_model.mjs` imports the existing exported `parseRegistryPages` from `scripts/generate_route_disposition.mjs` instead of duplicating the TypeScript registry parser. Existing scripts were not modified.
5. **Tab extraction.** The page-model CLI treats `<nav role="tablist">` and nav/tab-classed `<nav>` blocks as tab navigation and extracts static button/anchor labels plus `el-tab-pane label=`. This catches the current `PeopleWorkspace` module-wide shortcut nav without flagging ordinary in-page links.
6. **Primitive scope preserved from the brief.** Only files under `/modules/` are scanned; module `components/` files are skipped unless they are named `*Page.vue`. Shared UI outside `modules/` is out of scope, so `DataTable.vue` is not falsely flagged.
7. **Copy scope preserved from the brief.** All module `.vue` files are scanned except test paths and `/shared/ui/`, which is the approved allowlist.
8. **No gate weakening.** No tolerate list, no "baseline mode", no filtering to make the current repo pass.

## Baseline ownership

- `docs/audit/2026-11-26-v2-gate-baseline.md` records the exact JSON for all three gates.
- Every one of the 35 failures has an owner and close action:
  - Volume 2 Task 6 (user workspace) and Task 7 (role workspace) for platform pages;
  - Volume 3 Tasks 2/5/6/7/8/13/14/16 for People and Talent pages;
  - Volume 4 Task 7 for final shared-shell deletion.
- The doc states explicitly that the real gates are red and that only fixture tests are green.
- Registry baseline recorded as 160 entries / 158 `actions: []` from a measured `rg` command.

## Verification summary

| Check | Result |
|---|---|
| Three fixture tests (exact brief command) | PASS — 3/3 |
| All `scripts/tests/*.test.mjs` | PASS — 51/51 |
| `node --check` all three gate scripts | PASS |
| `git diff --check` target files | PASS |
| Gate output determinism (two runs each) | PASS — empty diffs |
| Real page-model gate | exit 1, 12 failures (expected baseline) |
| Real ui-primitives gate | exit 1, 11 failures (expected baseline) |
| Real copy gate | exit 1, 12 failures (expected baseline) |

## Concerns

1. **Task 4 artifact drift observed.** The repository does not contain `frontend/src/shared/navigation/pageModel.ts`, although Task 4's brief requires it. There is an unrelated working-tree modification to `frontend/tests/unit/navigation.spec.ts` referencing that missing module, created outside this task. Per scope, it was not touched or committed. The page-model gate was implemented to the Task 8 brief and consumes the registry directly, so it does not depend on that missing module. The lead should confirm Task 4's disposition/commit before Task 9.
2. **Page-model gate is intentionally narrow.** It enforces action-like paths and cross-module tab labels per the Task 8 brief. Detail-page menu invariants belong to the Task 4 page-model contract, not this gate; no extra rules were invented.
3. **Gate reports are file-level for repeated tags.** For a file with several raw inputs or enum codes, each rule reports once with the first line. Expansion to per-occurrence findings would change counts and is better done when Volume 2/3 clears the files, not as a Volume 1 contract change.
4. **Package scripts intentionally exit 1 today.** They are not wired into CI blocking, matching the approved Volume 1 exception; they become release gates in Volumes 2/3.

## Evidence files

- `.superpowers/sdd/artifacts/v1/task-8-fixture-tests.txt`
- `.superpowers/sdd/artifacts/v1/task-8-page-model.json` (and `.err` empty)
- `.superpowers/sdd/artifacts/v1/task-8-ui.json` (and `.err` empty)
- `.superpowers/sdd/artifacts/v1/task-8-copy.json` (and `.err` empty)
- `docs/audit/2026-11-26-v2-gate-baseline.md`

## Commit

```text
test(gates): add page, primitive and copy gates with baseline inventory
```

This report is included in that commit (the exact final SHA is reported in the task completion message; a self-referential SHA cannot be embedded in the same commit). Post-commit verification: fixture tests 3/3 pass; real gates still exit 1 with 12/11/12 failures (expected baseline).
