# Search Manager test architecture

The Search Manager test suite is organized by supported behavior. Test names should explain the contract under test and the expected outcome, so a failure can be understood without knowing the change that originally introduced the coverage.

## Directory responsibilities

- `Integration/` contains PHPUnit tests discovered by the normal suite. Each class covers one cohesive domain responsibility or behavior family.
- `Fixtures/` contains direct-only executable fixtures used by a specific test. They are not part of normal PHPUnit discovery and must run only when their owning test invokes them.
- `Support/` contains narrowly owned shared test infrastructure, such as lifecycle helpers or fixture builders used by more than one cohesive test class. Product behavior does not belong here.
- `Stubs/` contains reusable test doubles with explicit, limited contracts. A stub should model only the collaborator behavior required by its consumers.
- `js/` contains JavaScript tests. These tests follow the same behavior-oriented naming and isolation rules as the PHPUnit suite.

Test-local fixture models, anonymous collaborators, and helpers stay in the test file that owns them. Move code to `Support/` or `Stubs/` only when multiple focused classes genuinely share the same contract and ownership is clear.

## Naming and class scope

Files and classes use domain- or behavior-oriented names, such as `PendingSyncWakeupSchedulingTest` or `StorageMutationAtomicityTest`. A test class owns one cohesive responsibility. Narrow consolidation is appropriate when methods exercise the same supported contract and share meaningful setup; otherwise, create a focused sibling class.

Do not introduce names based on temporary work history. New test files, classes, methods, providers, fixtures, or helpers must not use audit IDs, PR IDs, debt labels, batch labels, amendments, smoke labels, or catch-all terms such as `Miscellaneous`, `RegressionBatch`, or `OtherTests`.

Test methods name the supported behavior and outcome. Prefer forms such as `testPendingRowsRemainRetryableAfterQueueFailure` over names that identify a ticket. Providers describe the input dimension or behavior cases and stay beside their sole consumer. A provider shared by multiple focused classes belongs in a narrowly named support owner, not in an unrelated test class.

Structural or source-text assertions are justified only when source structure itself is a supported safety contract that cannot be verified reliably through behavior. They should state the protected structural invariant and avoid restating ordinary implementation details.

## State and process isolation

Tests must own every database row, cache key, filesystem path, queue job, and process they create. Use exact IDs and test-specific prefixes, transactions where supported, and `finally` cleanup for resources that can survive an exception. Cleanup must target only the exact owned boundary; broad truncation, wildcard deletion, and restoration that can overwrite concurrent owner changes are prohibited.

Process tests must authenticate the child they own, verify process identity, reap descendants, and cover interruption and exceptional cleanup paths. Startup failures and partial setup must roll back only test-owned state. Existing owner-managed or unattributed application data must remain untouched.

Direct-only fixture programs must reject or remain inert during normal suite discovery. Their temporary paths and lifecycle are owned by the invoking test, including failure and interruption paths.

## Accounting and verification

Every rationalization records its canonical path ledger and reconciles added, removed, renamed, moved, and modified paths against the suite inventory. Public test methods, providers, lexical assertion calls, conditional skips, and runtime tests, assertions, and skips are counted before and after. Any intentional delta must identify the exact old checks, their replacement, and the invariant that remains protected.

Before handoff, run focused PHPUnit coverage for every changed behavior family, PHP syntax checks for every changed PHP file, and JavaScript syntax plus direct execution for changed JavaScript tests. Then run `composer ci`; run `composer ci:full` when the plugin's meaningful integration suite is affected. Runtime totals, skips, duration, memory, and owner-state comparisons must be recorded, and unexplained reductions or state drift must be resolved before proceeding.

The mechanical convention guard is `php scripts/check-test-conventions.php`. Its exception ledger records only pre-existing identifier debt. New exceptions fail, and an exception that disappears becomes stale and must be removed from the ledger, so the allowed set can only shrink. Comments, fixture values, and ordinary display strings are not identifiers; structured JavaScript `test()`, `it()`, and `describe()` names are checked without treating unrelated strings as declarations.

`composer quality-gate` is the complete package verification authority used by CI and Act. It covers Composer platform compatibility and audit, PHPStan/ECS, the convention guard, the disposable MySQL PHPUnit suite, hook/orchestration regressions, analytics JavaScript, widget unit tests, static accessibility, and the bulk-response handler. Live browser/provider checks and PostgreSQL runtime verification remain separate gates.

## Pending-sync worker recovery

`PendingSyncDeadlockRecoveryTest` launches `Fixtures/PendingSyncWorkerRecoveryTest.php` in a separate PHP process inside the runner's disposable Craft project/database. That process has no enclosing test transaction or connection-private temporary tables. It owns persistent clones of the pending and queue tables, a controlled lock table, unique index/entry IDs, and authenticated barrier files. `PendingSyncFaultCommand` routes pending SQL to the exact owned table and injects failures before execution; `PendingSyncConcurrentWorker.php` boots independent connections to those same tables. Existing process registration and the disposable runner own interruption cleanup.

The fixture reaches pending, dirty, failed, and abandoned states through supported saves, deletes, claims, processor results, and bookkeeping. It checks statement recovery separately from backend replay, overlapping claims, a save/completion lock wait, and a controlled real MySQL transaction rollback. The controlled lock cycle demonstrates rollback semantics; it does not reconstruct a production deadlock.

The complete gate includes an elapsed-time test lasting just over five minutes. It executes a real Craft queue job, preserves its failed record, waits through the actual claim lifetime, executes the successor, and checks backend output. For iteration only, run:

```bash
ddev exec "cd plugins/search-manager && composer test -- --exclude-group elapsed-worker-recovery tests/Fixtures/PendingSyncWorkerRecoveryTest.php"
```

Omit `--exclude-group elapsed-worker-recovery` for the complete focused family. The discovered wrapper reports one parent test; its child reports the full behavioral counts separately in captured diagnostics. Normal package verification remains PHP 8.3. Focused exception/PDO checks can run with `php8.2`, `php8.4`, or `php8.5` through `tests/Fixtures/Project/run.php`; PHP 8.2 may require a separate compatible vendor tree supplied with `SEARCH_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT`.

`Fixtures/PendingSyncPostgres.php` is a separate direct-only dialect check. Its caller must create and remove a dedicated ephemeral PostgreSQL container named `sm-pending-pg-` plus 12 lowercase hex characters, with the `worker_fixture` database/user/password, reachable from DDEV. Invoke it with that exact container name. It verifies repository SQL, token handling, and immediate PostgreSQL error propagation; it is not a full Craft producer or provider integration test. The caller owns container cleanup on every exit, and the fixture drops its table and temporary application directory.
