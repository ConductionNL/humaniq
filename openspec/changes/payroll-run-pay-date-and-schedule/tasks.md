## 1. Pay-date wait

- [ ] 1.1 Land a pay-date field (`paymentDayOfMonth`) on the administration surface, then an
      `openregister.wait` (or run-scoped FlowTimer) between approve and glpost in the Loonrun
      flow. (Was `payroll-run-as-a-flow` 7.1.)

## 2. Schedule adoption

- [ ] 2.1 Schedule adoption recipe on the admin/docs surface (`openregister.trigger-schedule`
      + explicit `runAs`), plus oversight registration if the scheduled flow qualifies.
      (Was `payroll-run-as-a-flow` 7.2.)

## 3. The flow as the only approver

- [ ] 3.1 Once adopted flows carry approval everywhere, guard the raw `status` edit so the
      recorded decision is the only approver. (Was `payroll-run-as-a-flow` 7.3.)
