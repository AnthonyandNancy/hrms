# 第 1 册验收记录（V2.1 Volume 1）

- Snapshot commit: `3b6a6239d72784ae68e1ab23febdd05082b20886`
- Report mode: recorder. A non-zero product gate does not make this generator fail.
- Evidence tails contain the last 1500 characters of captured stdout+stderr (per gate).

## Product gates

| gate | exit code | result | evidence tail |
| --- | --- | --- | --- |
| `backend-phpunit` | 1 | FAIL | rs/{id}/roles uses iam.role.manage, which is absent from actions Failed asserting that two arrays are identical. --- Expected +++ Actual @@ @@ -Array &0 [] +Array &0 [ +    0 => 'hr-contracts-renewals write API POST /v1/lifecycle/contract-renewals uses lifecycle.contract.manage, which is absent from actions', +    1 => 'hr-contracts-changes write API POST /v1/lifecycle/contract-renewals uses lifecycle.contract.manage, which is absent from actions', +    2 => 'system-users write API POST /v1/users/{id}/roles uses iam.role.manage, which is absent from actions', +]  D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\tests\Contract\PagePermissionCoverageTest.php:259  6) Tests\Architecture\ArchTestCommandTest::test_default_command_passes_for_current_application Expected status code 0 but received 1. Failed asserting that 1 matches expected 0.  D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\vendor\laravel\framework\src\Illuminate\Testing\PendingCommand.php:464 D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\vendor\laravel\framework\src\Illuminate\Testing\PendingCommand.php:676 D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\tests\Architecture\ArchTestCommandTest.php:75  FAILURES! Tests: 1135, Assertions: 52269, Failures: 6.  OpenApiSchemaContractTest: 0 success-schema violations across 323 operations  OpenApiSchemaContractTest: 0 error-set violations across 323 operations  OpenApiSchemaContractTest: 0 capability violations across 323 operations |

## Environment probes

- None recorded.

## Evidence

### backend-phpunit

- command: `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`
- exit code: 1
- result: FAIL

```text
rs/{id}/roles uses iam.role.manage, which is absent from actions
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
-Array &0 []
+Array &0 [
+    0 => 'hr-contracts-renewals write API POST /v1/lifecycle/contract-renewals uses lifecycle.contract.manage, which is absent from actions',
+    1 => 'hr-contracts-changes write API POST /v1/lifecycle/contract-renewals uses lifecycle.contract.manage, which is absent from actions',
+    2 => 'system-users write API POST /v1/users/{id}/roles uses iam.role.manage, which is absent from actions',
+]

D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\tests\Contract\PagePermissionCoverageTest.php:259

6) Tests\Architecture\ArchTestCommandTest::test_default_command_passes_for_current_application
Expected status code 0 but received 1.
Failed asserting that 1 matches expected 0.

D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\vendor\laravel\framework\src\Illuminate\Testing\PendingCommand.php:464
D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\vendor\laravel\framework\src\Illuminate\Testing\PendingCommand.php:676
D:\code\ai\factoryHR\.worktrees\v2.1-remediation\backend\tests\Architecture\ArchTestCommandTest.php:75

FAILURES!
Tests: 1135, Assertions: 52269, Failures: 6.

OpenApiSchemaContractTest: 0 success-schema violations across 323 operations

OpenApiSchemaContractTest: 0 error-set violations across 323 operations

OpenApiSchemaContractTest: 0 capability violations across 323 operations
```

## Conclusion

**NO — blocked**

- `backend-phpunit`: exit code 1; command `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`.

