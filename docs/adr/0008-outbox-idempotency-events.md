# ADR-0008: Transactional outbox, idempotency ledger and versioned event contracts

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-07
- **Supersedes:** none
- **Review trigger:** adding a real broker/dead-letter consumer, requiring a mandatory idempotency key on every command route, changing event envelope versioning, or finding a business write and its outbox row can commit separately

## Context and trigger

The approved architecture separates local business facts from integration
effects: a module must not make an external side effect inside its local
transaction, and a retry must not duplicate a command or lose an integration
event (D-07). The repository uses a transactional outbox, a server-side
idempotency ledger, and event payloads that carry IDs and versions rather than
domain payloads or PII.

This is a shared platform contract. Once consumers depend on event names or
payload shapes, changing them requires versioning and replay compatibility. The
reference implementation has the durable tables, enforcement, and tests, but it
does not claim a production broker, an automatic dispatch loop, or a dead-letter
runbook. This ADR records the implemented boundary and those gaps.

## Decision

1. **Outbox row joins the caller transaction.** `OutboxService::record()` only
   inserts an `outbox_events` row; it never opens or commits a transaction.
   Business services call it through `OutboxRecorder` inside the same
   transaction as their local write. If the transaction rolls back, the outbox
   row rolls back too.
2. **Stable event envelope.** `outbox_events` stores a ULID `id`, `tenant_id`,
   `event_type`, `aggregate_type`, `aggregate_id`, `aggregate_version`,
   `payload_json`, `occurred_at`, `available_at`, `published_at`, `attempts`,
   and `last_error`. The aggregate identity plus version is the consumer
   ordering key; a consumer must treat duplicate delivery as possible.
3. **Payload minimization and sensitive-key rejection.** `OutboxService`
   recursively rejects prohibited keys including identity numbers, credentials,
   bank/health/contact data, and tokens before insert. Workflow additionally
   sanitizes effect events to an allowlist of IDs and versions
   (`case_id`, `decision_id`, `effect_id`, `template_version`,
   `employment_id`, `aggregate_version`, `schema_version`), so a handler cannot
   leak case payload fields into an integration event.
4. **Versioned event type names.** Event types are closed code-owned strings;
   Workflow effect declarations validate the event type shape
   (`^[a-z][a-z0-9_.-]{2,127}$`), and domain events use a `.v1` suffix. A
   breaking payload change requires a new event type/version, not an in-place
   reinterpretation of an old name.
5. **Transport is an adapter.** `OutboxDispatcher::dispatch(string $eventId):
   bool` is the transport-neutral port. `LogOutboxDispatcher` logs the event,
   increments `attempts`, and marks `published_at`; it is the reference adapter,
   not a broker. A real broker adapter must preserve the same pending-event
   semantics and must not change module code.
6. **Idempotency is server-side and payload-bound.** `IdempotencyService`
   reserves `(tenant_id, principal_id, scope, key)` with a SHA-256 hash of a
   recursively key-sorted request payload. A matching completed reservation
   replays the stored HTTP status/body and sets `Idempotent-Replayed: true`.
   Reusing the key with a different payload is 409
   `IDEMPOTENCY_PAYLOAD_MISMATCH`; a second in-progress request is 409
   `IDEMPOTENCY_IN_PROGRESS`; a failed operation deletes its reservation and
   may be retried. The unique index is the concurrency backstop.
7. **Command scope is explicit.** The key is scoped by method and path. Module
   controllers that need mandatory idempotency call `IdempotencyRunner::run()`
   inside the same database transaction as the command. The
   `IdempotentCommand` middleware provides optional replay for routes that
   attach it when an `Idempotency-Key` is supplied; a missing key falls through
   rather than inventing one.
8. **Audit is separate from the outbox.** Audit is append-only evidence with
   actor and request correlation; the outbox is integration delivery. Both use
   the same transaction discipline, but neither replaces the other. The audit
   recorder also rejects sensitive keys and does not accept PII values.

### Known limitations of this implementation

- There is no production broker, no worker/scheduler that scans pending outbox
  rows, and no dead-letter queue or consumer lag metric. `LogOutboxDispatcher`
  is invoked directly only where an adapter chooses to dispatch; the repository
  does not claim end-to-end delivery.
- The partial index `outbox_pending_idx` supports scanning pending rows, but
  delivery is not scheduled by this reference build. Queue backlog, duplicates,
  and projection delay remain operational GATEs.
- `IdempotencyService` stores `expires_at` (24h) but does not filter or prune
  expired reservations in this build. A key after its nominal TTL can still be
  found and replayed; a scheduled expiry/cleanup policy is future work.
- The generic `IdempotentCommand` middleware does not itself open a transaction
  around the idempotency reservation and the downstream write. Mandatory
  module paths wrap `IdempotencyRunner::run()` in `DB::transaction`; generic
  middleware coverage and transactional guarantees must be reviewed before
  claiming every route is atomic.
- Event names are code-owned but not centrally registered in a schema registry.
  Consumers must rely on code review, the OpenAPI/contract tests, and event
  type naming discipline.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Publish to a broker synchronously inside the business transaction | A broker timeout/failure would either roll back valid local business state or leave state committed without its effect; external systems cannot participate in the database transaction. |
| Emit integration events from application logs only | Logs are not ordered durable evidence, cannot be replayed reliably, and make deduplication and delivery state invisible. |
| Use a database job row without a transactional outbox | Creates two independent writes and a crash window where the business fact commits but the event does not (or vice versa). |
| Put full domain payloads in events to avoid consumer lookups | Leaks PII, couples consumers to internal schemas, and makes every field change a breaking event-contract change. |
| Client-generated idempotency with no server reservation | Cannot prevent concurrent duplicate commands, payload reuse, or cross-principal collisions. |
| Treat any replay as success without storing the original response | Retries could return a different status/body or rerun side effects; a byte-stable stored result is the contract. |

## Consequences

Positive:

- local business state and outbox evidence are atomic under the tested caller
  transactions;
- duplicate client retries do not create duplicate commands on the mandatory
  paths;
- event payloads are ID/version-only and sensitive-key rejection is recursive;
- a broker can replace the log dispatcher without changing module services;
- event type/version and idempotency behavior are covered by executable tests.

Negative / costs:

- consumers must handle at-least-once delivery and deduplicate by event/aggregate
  version;
- every command that needs replay safety must supply a stable key and, for the
  strongest guarantee, wrap the reservation and write in one transaction;
- a production broker, retry/backoff policy, dead-letter runbook, dispatch
  scheduler, and expiry cleanup are not delivered here (GATE);
- event schema evolution and consumer compatibility need ongoing review;
- the outbox keeps a growing append-only event table until retention policy is
  defined.

Data/API/permission/migration/operations/training impact: Platform owns the
outbox and idempotency tables; business modules depend on Shared ports
(`OutboxRecorder`, `IdempotencyRunner`) rather than Platform models. Event
payloads are additive and versioned; a breaking change needs a new event type
and consumer migration. Operations need a delivery/backlog runbook and a
dead-letter process before production. Training must distinguish audit evidence
from event delivery and explain that a replayed response is not a second write.

## Validation

- `php artisan test --filter=OutboxServiceTest` — ULID/event persistence, caller
  transaction rollback, recursive sensitive-key rejection, and dispatcher port
  binding.
- `php artisan test --filter=IdempotencyServiceTest` — first execution vs replay,
  payload mismatch conflict, failed reservation release, in-progress conflict,
  and unique-index protection.
- `php artisan test --filter=IdempotencyHttpTest` — an HTTP command replay
  returns the original response and `Idempotent-Replayed: true` without a
  duplicate person; a different payload is 409 with
  `IDEMPOTENCY_PAYLOAD_MISMATCH`.
- `php artisan test --filter=PeopleOrgApiContractTest` — command outbox events
  contain no identity plaintext or mobile field and the audit event is present.
- `php artisan test --filter=OutboxOnDecisionTest` — Workflow external effect
  events contain only ID/version fields and an external effect is not silently
  marked applied.
- `php artisan test --filter=EffectIdempotencyTest` — effect retry budget and
  replayed decisions.
- `php artisan test --filter=ConcurrentApprovalTest` — replayed idempotency key
  returns the same decision/effect without duplicates.
- `php artisan test --filter=AuditAppendOnlyTest` — audit rows cannot be updated
  or deleted (the separate audit evidence guarantee).
- `php artisan test --filter=LogRedactionTest` — redaction at the real log
  channel and correlation fields in request logs.
- `php artisan arch:test` — Platform may not depend on business modules; the
  current repository run reports 16 non-blocking warnings, not zero warnings.

## Security and privacy

Outbox payload keys are rejected recursively before insert; Workflow effect
payloads are allowlisted to IDs/versions; idempotency responses can contain
business data and therefore inherit the same authorization and tenant scoping as
the original command. Idempotency keys are scoped by tenant, principal, method,
and path so one principal cannot replay another's command. Audit and request logs
use the shared sensitive-key lists and redaction processor. Production broker
credentials, TLS, KMS, consumer authentication, dead-letter access control, and
retention of event history remain operational/security GATEs.

## Review triggers and rollback

Re-review before adding a broker or consumer, before making idempotency
mandatory on every command route, before relying on TTL expiration, or before
changing any event envelope/name. A real adapter must define retry/backoff,
dead-letter handling, duplicate semantics, consumer lag metrics, and replay
procedures.

Rollback path: keep `OutboxService` and the outbox table as the source of
delivery intent and replace only the dispatcher adapter behind
`OutboxDispatcher`; do not write compensating business rows outside the module.
Reverting the idempotency table or unique index requires a migration and a
consumer/client compatibility plan. Breaking event changes require a new version
and dual-publish window. Owner: platform technical owner with security and
integration owners.
