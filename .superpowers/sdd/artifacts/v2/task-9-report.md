# Volume 2 Task 9 — Authorization consistency and administrator protection

Status: implemented and verified inside `.worktrees/v2.1-remediation`.
Commit: `feat(iam): enforce authorization consistency and last-admin protection` (the task commit containing this report).

## Delivered

### Snapshot consistency
- `AuthorizationSnapshot::replayRole(string $roleCode, string $reason): int` replays every user holding the role exactly once, even when multiple live `user_roles` rows exist for the same user. One outer transaction; one `auth_version` bump and one `authorization.snapshot.replayed` audit per affected user.
- `AuthorizationSnapshot::replayUsers(list<int> $userIds, string $reason): int` deduplicates ids and replays once each (fan-out primitive for page/scope/field saves).
- Binding cache key in `EloquentAuthorityBindingRepository` remains `iam:bindings:{principal}:{auth_version}:{crc32(candidates)}:{businessDate}`; tests prove the pre-mutation key is unreachable after the bump and version 1/2 keys are never served as generation 3.

### Session revocation
- `SessionManager::invalidateForAuthorityChange(int $userId, string $reason): array{sessions_revoked:int, auth_version:int}` performs `revokeAllForUser()` and exactly one `AuthorizationSnapshot::replay()` inside one transaction. Disable, role revocation, password reset and any authority change must use it.
- `SessionManager` now takes `AuthorizationSnapshot` via constructor; no manual construction exists outside Laravel's container (the only `new SessionManager(` hit is `Illuminate\Session\SessionManager`, a different class).
- Existing `PrincipalResolver`/`AuthenticateSession` `auth_version` check remains the durable backstop; a missed session row still cannot be used.
- Real-cookie test: disable via `invalidateForAuthorityChange`, then `GET /v1/me` returns 401 on the next request.

### Last-admin protection
New `App\Modules\IAM\Services\AdminProtectionGuard`:
- `assertNotLastAdminDisable(int $userId)` -> 422 `LAST_ENABLED_ADMIN_PROTECTED`.
- `assertNotLastAdminRoleRemoval(int $userId, list<int> $roleIds, ?int $actorUserId = null)` -> 422 same reason; non-admin role removal and cases with a surviving admin role are allowed.
- `isEnabledRoleManager(int $userId)`, `isSuperAdmin(int $userId)`, `otherEnabledAdminCount(int $exceptUserId)`.
- Admin identity = ENABLED user with a live, current-generation COMPANY-anchored `iam.role.manage`, `iam.binding.manage`, or `*` binding. `users.is_privileged` is never read.
- Shared `App\Modules\IAM\Services\RoleAdminService` is intentionally left at HEAD (owned concurrently by Task 6); guard wiring lives in `UserAdminController` via the Task 6 owner.

### Privilege-escalation ceiling
- `assertNoPrivilegeEscalation(actor, capability, anchor, fieldProfile)` -> 403 `PRIVILEGE_ESCALATION_FORBIDDEN` (via `AdminProtectionException`).
- `assertRoleGrantListAllowed(actor, grants)` checks every grant in a proposed role.
- `assertRoleAssignmentAllowed(actor, roleCode)` checks a stored role before assignment.
- `assertRoleGrantAllowed(actor, roleCode)` -> 403 `SUPER_ADMIN_GRANT_FORBIDDEN` when a non-super-admin tries to grant `SUPER_ADMIN`.
- Wildcard is a capability wildcard only: it never widens field profile or anchor. Actor ceiling is their own effective grants; a grant must not exceed actor's field profile rank or anchor breadth (COMPANY > ORG_SUBTREE > ORG_UNIT; MANAGED covers SELF; COMPANY covers all).

### No-bypass evidence
- `EnsureCapability` with a privileged-but-unbound principal returns 403 and never reaches the controller closure.
- `RequireMfaStepUp` fails closed with 403 `MFA_STEP_UP_REQUIRED` for a privileged principal without a fresh factor; MFA pass-through when disabled still leaves `RequireCapability` as the denial point (`CAPABILITY_DENIED`).
- `CapabilityAuthorizer::assertLiveSubject()` rejects a stale `auth_version` even when the principal carries `privileged=true`.
- `is_privileged=true` without a binding is not an admin, cannot disable-protect, and cannot grant.
- No `DELETE /v1/users/{id}` route exists; deletion cannot bypass the disable guard. Covered by a route-collection assertion.

## Tests
- `backend/tests/Feature/Authorization/AuthorizationConsistencyTest.php` — 6 tests:
  assignment/revocation bump once and cache keys version; page/capability grants visible only at new generation; scope/field mutations visible only after one replay each; `replayRole` dedupe; disable revokes real cookie + 401.
- `backend/tests/Feature/Authorization/AdminProtectionTest.php` — 16 tests:
  last enabled admin disable veto (and second admin clears it), disabled peers don't count, no-op for non-admin, last admin role removal/self-demotion, non-admin role removal allowed, capability escalation, field profile + anchor escalation, privileged-unbound not admin, unbound disable allowed, role grant list ceiling, SUPER_ADMIN grant restriction, no delete-user route, production route gate denial, EnsureCapability no short-circuit, MFA step-up no short-circuit, stale auth_version rejection.

## Wiring handoff (Task 6 / Task 7 owners)
`UserAdminController` (Task 6) should call inside the existing lock/transaction:
- `update()` when status != ACTIVE: `$guard->assertNotLastAdminDisable((int) $locked->id)` **before** writing status.
- `assignRole()` before writes: `$guard->assertRoleAssignmentAllowed($principal->userId(), (string) $role->code)` and, for the raw grants, `$guard->assertRoleGrantListAllowed($principal->userId(), $grants->map(...)->all())`.
- `revokeRole()`: resolve live `user_roles.id` values for the target and role code, then `$guard->assertNotLastAdminRoleRemoval((int) $user->id, $roleIds, $principal->userId())` before delete.
- Disable/revoke: use `$sessions->invalidateForAuthorityChange($userId, $reason)` instead of a separate `revokeAllForUser()` + `replay()` so the bump is exactly once.
- Role authoring (`RoleAdminService` create/copy/replaceCapabilities) should call `assertRoleGrantListAllowed()` before persisting if delegated role authoring must also be ceiling-bound. This was intentionally not applied to avoid a concurrent-writer conflict.

`RolePageService::replace()` and `AuthorizationAdminController` binding create/revoke already replay once per affected user; they can adopt `replayRole()`/`replayUsers()` when convenient, but their current paths are single-bump and covered by existing tests.

## Verification (run 2026-11-26)
- `phpunit --filter='AuthorizationConsistencyTest|AdminProtectionTest'` — OK (22 tests, 82 assertions).
- `phpunit --filter='Authorization|IAM'` — OK (296 tests, 7819 assertions).
- `phpstan analyse --no-progress` — task-owned files OK; full tree at commit time had 2 remaining errors in `MenuAdminController` (Task 8/7 in-flight, lines 37–38 duplicate keys).
- `pint --test` — task-owned files clean; full tree at commit time still had Task 7/8 files (`routes/api.php`, `RoleAuthorizationController`, etc.) dirty.
- `artisan capability:audit` — `Capability audit OK.`

## Notes / concerns
- The brief's Step 2 says last-admin disable should be 409; the corrected requirement for this task is **422 + `LAST_ENABLED_ADMIN_PROTECTED`**, and that is what the guard returns (machine code stable across disable, role removal, self-demotion).
- `DELETE /v1/users/{id}` is not an exposed operation, so "delete last admin user" is covered by absence-of-route plus the disable guard; if a delete route is added later it must call `assertNotLastAdminDisable` before deleting.
- `AuthorizationDeniedException` in `AuthenticationFailedException.php` is not PSR-4 autoloadable; the new 403 path uses `AdminProtectionException` in its own file instead of touching that unrelated file.
- Full-tree gates are shared with concurrently running Task 6/7 agents; final integration must re-run phpstan/pint after their edits settle.
