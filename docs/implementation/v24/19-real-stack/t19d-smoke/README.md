# T19 v24-t19d overlay image smoke

- Date: 2026-11-28
- Source HEAD: `6167810`
- Base image: `factoryhr/php-app:v24-t19c`
- Overlay image: `factoryhr/php-app:v24-t19d` (`9067352ef201`)
- Overlay copies only:
  - `CapabilityAuthorizer.php` — COMPANY wildcard covers run/case routes;
  - `UserAdminController.php` — overlapping role bindings merge per version.
- Both files passed `php -l` in the image build.

## Deployed smoke

- php-app/php-worker/php-scheduler now run `factoryhr/php-app:v24-t19d`.
- nginx was restarted after the backend container replacement to refresh upstream DNS.
- `/v1/health`: **200**, checks db/cache/storage=true (`health-v24-t19d.json`).
- Real overlap API: adding AUDITOR to `t19.payroll` returned **201**
  (`overlap-response.json`).
- Effective `auth_version=7`:
  - `org.tree.read` COMPANY = **1 row**
  - `people.employee.read` COMPANY = **1 row**
  (`merged-bindings.txt`)
- Real user session:
  - `GET /v1/payroll/runs/1` = **200**
  - `GET /v1/payroll/runs/2` = **403**
  (`run-status.txt`, `run-1.json`, `run-2.json`).

## Explicit boundary

This is a reproducible **overlay** image on the existing release base, not a
fresh full Dockerfile build. The clean `v24-t19d` source build remains blocked by
slow/stalled Alpine package installation and must be completed before a release
GO. No credentials or real personal data are stored here.
