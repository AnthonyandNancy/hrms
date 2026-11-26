# ADR-0036: Action Manifest Is Generated from Capability and OpenAPI

- Status: Accepted — 2026-11-26
- Date: 2026-11-26
- Approval: 用户 2026-11-26 批准 FactoryHR V2.1 全量整改规格与四册计划；本 ADR 为该批准的可执行化
- Related: ADR-0035, ADR-0027

## Context

The frontend page registry hand-wrote its own `actions` arrays while the backend
`PermissionResourceCatalog` and OpenAPI each described write capabilities
separately. At adoption 158 of the then-160-entry registry declared `actions: []`, so
role administration cannot show which buttons a page really supports, and a
write API can exist without a corresponding action or vice versa. Any
hand-maintained second list would repeat the same drift, and serving the
documentation file to the browser would couple runtime availability to a repo
path and invite runtime file access.

## Decision

1. Backend CapabilityCatalog and OpenAPI are the only action truth sources.
2. `docs/generated/page-actions.json` is generated, never hand-edited.
3. Frontend imports a generated TypeScript module (`frontend/src/generated/pageActions.ts`) at build time; it never reads `docs/` at runtime and never enables runtime file access.
4. CI fails when a write API lacks an action, an action lacks an API, or capability differs.
5. Runtime never reads documentation files.

## Alternatives

- Keep hand-written action arrays in `frontend/src/router/registry.ts`: rejected; they already drifted to 158 empty pages and cannot be trusted by role administration.
- Let the frontend read `docs/generated/page-actions.json` at runtime: rejected; it makes a build artifact a runtime dependency, exposes repo layout and requires runtime file access.
- Derive actions from OpenAPI alone: rejected; OpenAPI has no page code, label, confirmation or field profile, and capability semantics live in `PermissionResourceCatalog`.
- Generate the manifest from the registry instead: rejected; the registry is the consumer, so generation would preserve the same missing/incorrect actions instead of detecting them.

## Consequences

- Action inventory becomes reproducible and reviewable as a generated diff rather than scattered literals.
- A capability or OpenAPI change now forces a manifest regeneration; CI fails instead of silently diverging.
- Page registry entries reference generated action keys and no longer hand-maintain `actions`.
- `docs/generated/page-actions.json` and `frontend/src/generated/pageActions.ts` must be regenerated and committed with any write API change.
- Build tooling must fail closed when either truth source or the committed generated file is missing or stale.

## Validation

- `pnpm actions:generate` produces a deterministic file.
- `pnpm actions:check` fails on each mismatch class in fixture tests.

## Review trigger

A new action source is introduced (for example a second API gateway or a plugin-provided capability catalog), or generated artifacts can no longer be reproduced deterministically in CI.
