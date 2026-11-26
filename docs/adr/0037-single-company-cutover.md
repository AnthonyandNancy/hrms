# ADR-0037: Single-Company Cutover Execution

- Status: Accepted — 2026-11-26
- Date: 2026-11-26
- Approval: 用户 2026-11-26 批准把单公司 cutover 纳入本次，并接受代码与迁移一次完成
- Supersedes: ADR-0034 migration execution notes only
- Related: ADR-0034

## Decision

1. `company_profile` is a singleton configuration, not a data boundary.
2. `LEGAL_ENTITY` bindings migrate to `COMPANY` with anchor_id null and anchor_key 0.
3. Data conflicts abort the migration; they are never silently merged.
4. Production deletion requires snapshot, rehearsal, restore verification and a window.
5. Runtime code may not reference tenant or legal-entity identifiers after cutover.

## Validation

- `single-company:check` scans app, routes, seeders, frontend/src and generated contracts.
- Migration rehearsal proves forward migration, checksums and restore.
