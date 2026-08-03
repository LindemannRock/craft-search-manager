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
