# HRMS V1.0 Interface Freeze (for parallel implementation)

Status: binding for all implementation tasks unless changed by ADR.
Derived from approved solution v1.1 and plan v1.1.

## Namespaces

All application code under `App\`.

- `App\Shared\*` — cross-cutting primitives; no business rules.
- `App\Modules\<Module>\*` — module code.
- Modules: IAM, Organization, People, Lifecycle, Workflow, Time, Payroll, Talent, Engagement, Reporting, Platform.
- Each module may expose only: `Contracts\*`, `Http\*`, `Models\*`, `Services\*`, `Policies\*`, `Providers\*`.
- Other modules must depend only on a module's `Contracts` interfaces + IDs/DTOs, never on its `Models` or internal services.

## Global identifiers

All primary keys: BIGINT unsigned auto-increment except event IDs (ULID string).
All tables carry `tenant_id`.
Business dates: `DATE` columns, half-open `[valid_from, valid_to)`, open end `9999-12-31`.
Recorded timestamps: `DATETIME(6)` UTC.
Every effective-dated table has: `valid_from`, `valid_to`, `recorded_at`, `superseded_at` nullable, `source_type`, `source_id` nullable.

## API conventions

- Prefix `/v1`.
- Envelope: `{"code":"OK","message":"...","data":...,"request_id":"..."}` for success; errors use stable code strings.
- Lists: `data = {items:[], page:1, page_size:20, total:0}`.
- Commands accept `Idempotency-Key` header; replay returns original status/body.
- Mutation routes carry `capability:<code>` middleware metadata for capability audit.
- 401 unauthenticated; 403 no capability; 404 missing or out of scope; 409 state/idempotency conflict; 422 validation; 429 throttled.
- OpenAPI at `docs/openapi/openapi.yaml` is the contract source; endpoint additions must update it.

## Auth principal contract (IAM owns)

`App\Modules\IAM\Contracts\Principal`:
- `userId(): int`
- `tenantId(): int`
- `employmentId(): ?int`
- `authVersion(): int`
- `isPrivileged(): bool`
- `hasMfa(): bool`

Authorization entry points (Shared\Authorization):
- `Authorizer::constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder`
- `Authorizer::allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool`
- `Authorizer::fields(Principal $p, string $capability, ResourceRef $row): FieldSet`

Capability codes are code-owned; `capability:audit` fails on unguarded `/v1` routes.

## Core domain contracts

People:
- Person 1—N Employment; Employment 1—N Assignment.
- `employment_id` is the universal reference for payroll/time/contract facts.
- only one PRIMARY assignment may cover a given business date for an Employment.
- assignment org must belong to the employment's legal entity, unless explicit secondment record.

Workflow:
- `POST /v1/cases` creates a Case with `type`, `subject_type`, `subject_id`, `payload`.
- `POST /v1/cases/{id}/decisions` records immutable Decision (`APPROVE|REJECT|RETURN|WITHDRAW|DELEGATE`).
- Local deterministic Effect applies in the same DB transaction as the approving Decision.
- Effect states: `SCHEDULED|APPLIED|FAILED|REVERSED`.
- `GET /v1/cases/{id}/timeline` returns application status and effect status separately.
- Unique: `(case_id,node_id,actor_id,decision_seq)`; Effect unique `(effect_type,aggregate_id,effect_seq)`.

Time:
- Leave accounts: GRANT, RESERVE, CONSUME, RELEASE, EXPIRE, ADJUST, REVERSE.
- Balance = sum(signed quantities) by employment/leave type/account.
- `attendance_days` unique `(employment_id,business_date)`; punch records immutable.
- Payroll input comes from contracts, never reads Time tables directly.

Payroll:
- Run states DRAFT→INPUT_LOCKED→CALCULATED→REVIEWED→APPROVED→PUBLISHED→PAID→CLOSED; corrections are new runs referencing original.
- Money stored as integer minor units; rates as decimal strings scaled 1e6; no PHP float in payroll.
- Published/closed runs immutable; correction run delta = new gross/net minus original.

## Error codes (minimum)

OK, CREATED, ACCEPTED, UNAUTHENTICATED, FORBIDDEN, NOT_FOUND, CONFLICT, VALIDATION_FAILED, RATE_LIMITED, INTERNAL, SERVICE_UNAVAILABLE.

## Migration/seed rules

- Migrations use anonymous classes and are forward-only.
- Seeders idempotent.
- Reference demo tenant: code `DEMO`, one legal entity `DEMO-CN`, timezone `Asia/Shanghai`.
- No real personal data in seeds; synthetic names and clearly fake identifiers.

## Test conventions

- PHPUnit 12, SQLite in-memory or temp file.
- Test names describe behaviour.
- Permission negative tests mandatory for every guarded endpoint: unauthenticated, no capability, wrong anchor, field denial.
- Fake clock via injectable `Clock` contract (`App\Shared\Time\Clock`), never `now()` directly in domain/payroll/time logic.
- No assertions on unstable ordering; use explicit IDs or sort keys.
