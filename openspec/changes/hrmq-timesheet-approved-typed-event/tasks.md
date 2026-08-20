# Tasks — hrmq-timesheet-approved-typed-event

## Status — IMPLEMENTED 2026-08-20

Applied in full in one pass. All items below are done; verification output is recorded at the
bottom of this file.

## 1. Typed event class

- [x] 1.1 `lib/Event/TimesheetApprovedEvent.php` — `OCA\Hrmq\Event\TimesheetApprovedEvent extends
  OCP\EventDispatcher\Event`, carrying `eventId`, `timesheetId`, `employeeId`, `period`,
  `periodGrain`, `hours`, `projectId`, `costCenter`, `billable`, `clientRef`, `administrationId`,
  `approvedBy`, `approvedAt` — mirrors pipelinq's `PosStockMovedEvent` shape (plain typed getters,
  no logic beyond the pure `classifyPeriodGrain()` classifier).
- [x] 1.2 `classifyPeriodGrain(string $period): string` static classifier — `GRAIN_MONTH` /
  `GRAIN_WEEK` / `GRAIN_DAY` / `GRAIN_UNKNOWN`, matching `Timesheet.period`'s three documented
  shapes plus a safe fallback for anything else.

## 2. Additive dispatch in TimeEntryEventService

- [x] 2.1 Constructor gains `IEventDispatcher $eventDispatcher` (Nextcloud DI auto-wires it; no
  `Application.php` change required — verified `TimesheetApprovalListener`, the sole caller, is
  itself container-constructed).
- [x] 2.2 `buildTypedEvent(array $timeEntry): TimesheetApprovedEvent` — builds the typed event from
  the same approved-Timesheet payload shape `buildApprovedEvent()` already reads, plus the derived
  `periodGrain`.
- [x] 2.3 `dispatchTypedEvent(array $timeEntry): void` (private) — `dispatchTyped()` wrapped in
  try/catch, logs and swallows on failure. Never throws.
- [x] 2.4 `maybeDispatchApproved()` calls `dispatchTypedEvent()` BEFORE the existing
  `dispatch($this->buildApprovedEvent($newData))` webhook call, on the same approval edge, with the
  webhook call's own return-value contract left completely unchanged.

## 3. PHPUnit coverage

- [x] 3.1 `tests/Unit/Service/TimeEntryEventServiceTest.php` — `serviceWithSpy()` wires a mocked
  `IEventDispatcher` recording every `dispatchTyped()` call into `$this->typedDispatches`.
- [x] 3.2 Existing `testSubmittedToApprovedEmitsEvent` extended to assert the typed event's payload
  alongside the existing webhook assertions.
- [x] 3.3 Existing negative tests (`testUnapprovedTransitionDoesNotEmit`,
  `testAlreadyApprovedDoesNotReEmit`, `testNonTimesheetSchemaDoesNotEmit`) extended to assert the
  typed path also stays silent.
- [x] 3.4 New `testTypedDispatchFailureDoesNotBlockWebhook` — typed dispatch throws, webhook still
  fires.
- [x] 3.5 New `testBuildTypedEventClassifiesMonthGrain` / `...WeekGrain` / `...DayGrain` /
  `...UnknownGrain` — pin the grain classification through the service's own `buildTypedEvent()`.
- [x] 3.6 New `testBuildTypedEventCarriesAdministrationId`.
- [x] 3.7 New `tests/Unit/Event/TimesheetApprovedEventTest.php` — getters +
  `classifyPeriodGrain()` data-provider coverage (9 cases) independent of the service.

## 4. Verification (this change, MEASURED 2026-08-20)

Run against a fresh clone of `ConductionNL/hrmq` on `development` (`ceb687f`), with `vendor/`
copied in from a sibling checkout at the same `composer.lock` (this host's `vendor/` install is
blocked — pre-existing, tracked in `hrmq-boot-integrity` tasks 5.1-5.6 — vendor is root-owned) and
`vendor/conduction/hydra-gates` copied in at the exact locked version (`v1.8.0`) from a sibling
app's install, per the workaround `hrmq-boot-integrity` task 6.1 already documented.

- `php -l` on all 4 changed/new files: **no syntax errors** (each individually).
- `./vendor/bin/phpunit --colors=never`:
  - BEFORE (original `development` files swapped back in, no git write op used — a plain file
    swap against `git show development:<path>`): `OK (1161 tests, 4575 assertions)`.
  - AFTER (this change applied): `OK (1177 tests, 4624 assertions)` — +16 tests, +49 assertions,
    zero regressions, zero failures.
- `composer check:strict` (lint + phpcs + phpmd + psalm + phpstan + test:all, full repo):
  **ALL CHECKS PASSED**. phpcs initially warned (0 errors) on every new public method missing an
  `@spec` tag and on the pre-existing (unmodified) `TimeEntryEventService::now()` — all fixed in
  this pass, including the pre-existing one per the fleet's "always fix pre-existing issues
  encountered during a task" rule. phpmd initially flagged `ExcessiveParameterList` on the 13-arg
  constructor and `StaticAccess` on the pure `classifyPeriodGrain()` call — both resolved with
  `@SuppressWarnings` annotations following the exact precedent already used in
  `PayrollReproduceService`/the `Lifecycle/*Guard` classes. Both re-runs after the fix are clean:
  0 errors, 0 warnings (phpcs); 0 findings (phpmd). psalm: `No errors found` (9 pre-existing-style
  info-level `MissingClassConstType` notices, matching the untyped-const style already used
  throughout this file and the rest of hrmq — not introduced debt). phpstan: `No errors`.
- `openspec validate hrmq-timesheet-approved-typed-event --strict`: `Change
  'hrmq-timesheet-approved-typed-event' is valid`.
