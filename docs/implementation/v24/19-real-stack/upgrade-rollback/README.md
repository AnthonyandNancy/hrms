# T19 same-database rollback/forward deployment

- Date: 2026-11-28
- Compose project: `factoryhr-t19`
- Database retained across both deployment generations.
- Runtime code diff from the `v24-t19c` base to HEAD is exactly:
  - two backend files (both copied into the `v24-t19d` overlay);
  - two frontend role-anchor files (already present in nginx `v24-t19b`).

## Sequence

1. Baseline DB: users=2, employments=0, payroll_runs=2, cases=0,
   migrations=99 (`db-before.txt`).
2. Deployed `factoryhr/php-app:v24-t19c`; health **200**.
3. Deployed `factoryhr/php-app:v24-t19d`; health **200**.
4. Re-ran the same DB counts; no diff (`db-v24-t19c.txt`,
   `db-v24-t19d.txt`).
5. Post-deploy login as `t19.payroll`: **200**; run #1 **200**, run #2
   **403** (`post-deploy-login.txt`, `post-deploy-run-*.json`).

## Boundary

The image path is an incremental overlay on the immediately preceding release
base, justified by the runtime-code diff. A fresh full Dockerfile build remains
a separate release-hardening item; it is not claimed here as completed.
