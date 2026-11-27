## 1. Pay-date wait

- [x] 1.1 Land a pay-date field (`paymentDayOfMonth`) on the administration surface, then an
      `openregister.wait` (or run-scoped FlowTimer) between approve and glpost in the Loonrun
      flow. (Was `payroll-run-as-a-flow` 7.1.)
      Progress: `hrAdministration.paymentDayOfMonth` (schema 1.9.0, en/nl), `PayDate::forPeriod()` and `payroll.payDate` on the calculate node's outcome are in (`PayDateTest`, `PayrollCalculateNodeTest`). Done: the Loonrun's `payday` step (`openregister.wait`, `until: "{{ payroll.payDate }}"`) sits between approve and glpost now ConductionNL/openregister#11043 is on OpenRegister main; an empty pay date passes through (`PayrollFlowDeclarationTest::testAnApprovedRunWaitsForItsPayDateBeforeItIsPosted`). The v1 repair splices the step back out to recognise an untouched v1 import (`FlowStepSplicer::withoutStep()`, `LoonrunFlowRepairServiceTest`). Docs: `docs/payroll/loonrun-flow.md`.

## 2. Schedule adoption

- [x] 2.1 Schedule adoption recipe on the admin/docs surface (`openregister.trigger-schedule`
      + explicit `runAs`), plus oversight registration if the scheduled flow qualifies.
      (Was `payroll-run-as-a-flow` 7.2.)
      Done: `docs/payroll/loonrun-flow.md` gives the adoption steps and the schedule recipe (`openregister.trigger-schedule`, cron, explicit `runAs` service account). Decision on oversight: a scheduled Loonrun still stops at its human review task before anything is approved, posted or paid, so it is not autonomous work and is not registered; the page says to register it if the review step is removed.

## 3. The flow as the only approver

- [x] 3.1 Once adopted flows carry approval everywhere, guard the raw `status` edit so the
      recorded decision is the only approver. (Was `payroll-run-as-a-flow` 7.3.)
      Done: "adopted" is read per instance as the shipped Loonrun flow being enabled
      (`ShippedFlowAdoption`, by import identity). `PayrollRunApprovalGuard` (pre-save, PayrollRun)
      then refuses a write that moves a run from draft, or a create, into approved, posted or paid,
      naming the review step; the approve step writes under the internal-write marker and passes, a
      shipped import too. A disabled flow refuses nothing, and a flow store that cannot be read
      counts as not adopted (logged). PHPUnit: `PayrollRunApprovalGuardTest`,
      `ShippedFlowAdoptionTest`, `PayrollApproveNodeTest::testApprovalIsWrittenUnderTheInternalWriteMarker`.
      Docs: `docs/payroll/loonrun-flow.md`. The scenario stays `@e2e exclude` (server-side guard).
