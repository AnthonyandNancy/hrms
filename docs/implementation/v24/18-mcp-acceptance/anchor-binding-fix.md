# T18 role-anchor construction fix

## Gap

The role scope editor exposed CASE and PAYROLL_RUN anchor types in the selector
but only rendered explanatory text. It always submitted `anchor_id = null`, while
the backend correctly requires an id for both anchor types. This blocked R04/R08
and any case/run-scoped role construction in MCP.

## Change

- Added numeric 案例编号 / 工资批次编号 inputs for CASE and PAYROLL_RUN.
- Preserve the current anchor id when switching between anchor types that
  require an id.
- SystemRolesPage now validates and submits `anchor_id` for ORG_UNIT,
  ORG_SUBTREE, CASE and PAYROLL_RUN, while keeping SELF/MANAGED/COMPANY id-less.
- Added component regressions for CASE id 77→88 and PAYROLL_RUN id 9.

## Verification

- `systemRolesPage.spec.ts`: 8 tests pass; typecheck/lint green.
- Full gate run follows before the image rebuild.

## Real MCP confirmation

- Rebuilt nginx SPA at `aeccadc`; real stack health stayed db/cache/storage=true.
- `T19-MCP-002` copied `PAYROLL_SPECIALIST` to custom role
  `PAYROLL_SPECIALIST_T19` through the real UI and observed numeric
  `工资批次编号` inputs on the 数据范围 tab.
- MySQL confirmed the custom role persisted (`is_template=0`).
- Remaining role-construction step: create a real run, save its id into the
  binding, assign the role, then verify scoped access.

## T19-MCP-003 confirmation

- Real UI saved `payroll.run.read / PAYROLL_RUN / 1`.
- Real API run #1 = 200, run #2 = 403 for the synthetic payroll specialist.
- A second real defect was found and fixed: the installer's COMPANY wildcard was
  not matching PAYROLL_RUN-guarded routes. The regression is in
  `SuperuserFullAuthorityTest`; after the fix the member API returned 201 in the
  running stack.
- Final backend image rebuild is still pending; the running validation used a
  hot-patched same-release runtime and is labeled accordingly.
