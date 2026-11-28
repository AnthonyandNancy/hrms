# T19 concurrent idempotent role creation

- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Session: synthetic `installer`
- Build: `aeccadc`
- Endpoint: `POST /v1/roles`
- Idempotency-Key: `t19-concurrent-role-2`
- Request body: `body2.json` equivalent, role code `T19_CONCURRENT_ROLE_2`

Two curl processes were launched concurrently with the same CSRF token,
Idempotency-Key and JSON body.

## Observed

- HTTP statuses: **201** and **200**.
- Replay header on the second completed response:
  `Idempotency-Replayed: true`.
- Database:
  - roles with code `T19_CONCURRENT_ROLE_2`: **1**
  - idempotency rows for the key: **1**
- Both response bodies identify the same role code; one is `CREATED`, the other
  is the replayed `OK` response.

## Files

- `status-summary.txt`
- `response-1.json`, `response-2.json`
- `response-1-headers.txt`, `response-2-headers.txt`
- `replayed-headers.txt`
- `idempotency-columns.txt`, `idempotency-count.txt`
- `db-counts.txt`

No cookie, CSRF token, password or session id is stored in these files.
