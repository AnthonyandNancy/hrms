# Volume 2 Task 3 — Workflow sweep report

Status: complete. Commit `064ea20` — `refactor(workflow): remove tenant and legal-entity scope`.

## Scope

Cleaned the Workflow module module-wide at lead direction: the 31 files in
`.superpowers/sdd/artifacts/v2/sweep/workflow.txt` plus `Workflow/Contracts/**`,
Workflow tests and `tests/Support/Workflow*` helpers/fakes.

Removed:

- `tenant_id` columns/filters/keys from all Workflow models and every case,
  decision, task, effect, event, member, instance, delegation and template write.
- tenant arguments from Workflow contracts and DTOs (`WorkflowPrincipal`,
  `ActorSnapshot`, `DomainEffectCommand`, `WorkflowCaseRecord`, case command /
  decision ports, gateway, assignee/eligibility, payroll approval crossing,
  reporting approval directory, delegation revoker).
- `caseForTenant()` → `caseFor()`; template and case-number lookups are now
  company-wide without a tenant predicate.
- `legalEntityForCase()` from the Workflow case-membership adapter; adapted to
  the shared tenant-free `CaseMembershipDirectory`.
- `--tenant-id` from `workflow:seed-templates`; seed idempotency now uses
  code/version only.

Preserved unchanged: idempotency scopes/keys and replay semantics, decision
ordering/sequence and duplicate guards, local-effect savepoint/retry/escalation
behaviour, effect create/apply ordering, outbox ID/version-only envelope,
delegation validity/amount/scope semantics, and SoD/eligibility enforcement.

## Verification

- `rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|legalEntityId' backend/app/Modules/Workflow backend/tests/Support/Workflow* backend/tests/Feature/Workflow` → **0**
- `php artisan arch:test` → **Module boundaries OK** (4 pre-existing non-blocking warnings)
- Targeted PHPStan (Workflow app + Workflow tests) → **No errors**
- Targeted Pint (`Workflow` app/tests + Workflow support fakes) → **PASS, 98 files**
- `php artisan test --compact --filter='Tests\\Feature\\Workflow'` → **51 passed, 348 assertions**
- Broader `--filter='Workflow'` → **89 passed, 469 assertions**

Full-repo `pint --test` and `phpstan analyse` were still red on files owned by
other concurrent sweeps, not on Workflow paths. The required `Tests\\Feature\\Workflow`
filter resolves to 51 tests; the unescaped single-backslash form matches zero.

## External call sites (not edited by this sweep)

These consume the new Workflow contracts and were updated by their owning
module sweeps; listed for cross-check:

- `backend/app/Modules/IAM/Auth/WorkflowPrincipalBridge.php:8,38`
- `backend/app/Modules/IAM/Services/IamAssigneeDirectory.php:8,20,34,42,58`
- `backend/app/Modules/Time/Adapters/WorkflowCaseAdapter.php:9,10,11`
- `backend/app/Modules/Time/Providers/TimeServiceProvider.php:26,27`
- `backend/app/Modules/Lifecycle/Services/LifecycleService.php:11,43,59`
- `backend/app/Modules/Lifecycle/Services/LifecycleCaseListService.php:8,42`
- `backend/app/Modules/Lifecycle/Services/LifecycleProjectionService.php:10,25`
- `backend/app/Modules/Lifecycle/Console/SeedLifecycleTemplatesCommand.php:10,23`
- `backend/app/Modules/Lifecycle/EffectHandlers/OffboardEffectHandler.php:15-18`
- `backend/app/Modules/Talent/Services/RecruitmentService.php:13,54,218,371,373`
- `backend/app/Modules/Talent/Services/PerformanceService.php:11,168,301`
- `backend/app/Modules/Payroll/Services/ApprovalService.php:10,77`
- `backend/app/Modules/Payroll/Services/CompensationService.php:12,13,177`
- `backend/app/Modules/Reporting/Services/ProjectionBuilder.php:14,15,56`
- `backend/app/Modules/Engagement/Adapters/NoticeApprovalWorkflowAdapter.php:8-11`
- `backend/app/Modules/Engagement/Services/NoticeGovernanceService.php:596,602`

## Commit discipline

Staged only the 65 explicitly listed Workflow module and Workflow test/support
paths. No `git add -A`, no Shared/IAM/other module/route edits.
