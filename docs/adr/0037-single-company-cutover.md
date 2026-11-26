# ADR-0037: Single-Company Cutover Execution

- Status: Accepted — 2026-11-26
- Date: 2026-11-26
- Approval: 用户 2026-11-26 批准把单公司 cutover 纳入本次，并接受代码与迁移一次完成
- Supersedes: ADR-0034 migration execution notes only
- Related: ADR-0034

## Context

ADR-0034 accepted removing tenant and legal-entity concepts, but its migration
section still described merging pre-launch rows, keeping a one-release
transition and validating a down-migration. The system is not live, and the user
on 2026-11-26 approved deleting the old data rather than deduplicating it into a
single company. The existing schema carries `tenant_id`/`legal_entity_id` across
roughly 139 tables and `LEGAL_ENTITY` authorization bindings, so a partial
cutover would leave two competing data boundaries and unverifiable uniqueness.

## Decision

1. `company_profile` is a singleton configuration, not a data boundary.
2. `LEGAL_ENTITY` bindings migrate to `COMPANY` with anchor_id null and anchor_key 0.
3. Data conflicts abort the migration; they are never silently merged.
4. Production deletion requires snapshot, rehearsal, restore verification and a window.
5. Runtime code may not reference tenant or legal-entity identifiers after cutover.

## Alternatives

- Preserve and deduplicate existing rows into one company: rejected; the system is not live and the user approved deleting the old data, so merge logic would add risk without preserving any production history.
- Keep the old columns behind a compatibility layer for one release: rejected; it leaves two data boundaries and lets new code depend on them.
- Rewrite the already-merged migrations in place: rejected; migrations are forward-only and the cutover must remain replayable from a snapshot.
- Roll back with a down-migration: rejected; a partially applied destructive cutover cannot reliably reconstruct deleted columns and rows, so the only rollback is restoring the pre-cutover snapshot.

## Consequences

- The database is rebuilt: test, staging and the real stack are dropped and recreated with `migrate:fresh --seed`. The system is not live and the user approved deletion of old data.
- Rollback is snapshot restore, not down-migration. The migration's `down()` stays empty; recovery means stopping the cutover and restoring the verified pre-cutover snapshot.
- `LEGAL_ENTITY` bindings become `COMPANY` (`anchor_id` null, `anchor_key` 0); capability codes, seeds, contracts and frontend context must move in the same cutover.
- Single-company business-key uniqueness must be rebuilt after legacy columns are dropped, or duplicate usernames, employee numbers and organization codes become possible.
- The cutover needs a production-like MySQL rehearsal plus snapshot/restore verification, not only an SQLite test run.
- Any attempt to reintroduce a second company requires a new ADR and a new data boundary, not a reinterpretation of `company_profile`.

## Validation

- `single-company:check` scans app, routes, seeders, frontend/src and generated contracts.
- Migration rehearsal proves forward migration, checksums and restore.

## Review trigger

The system becomes live with data that must be preserved; a second company or legal entity is required; or a snapshot restore can no longer be verified before a cutover.
