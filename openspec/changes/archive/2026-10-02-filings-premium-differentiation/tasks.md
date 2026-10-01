## 1. Tariff

- [x] 1.1 Add `AwfTariffResolver` with the BBL and young part-timer cases and use it in
      `PayrollRunService`, `RetroAdjustmentService` and `NlPayrollChecks`. Verify: unit tests for
      permanent, fixed-term, BBL, a 19-year-old on 10 hours and on 16 hours; the anchor payslip
      test is unchanged.
- [x] 1.2 Widen the `nl-awf-laag-hoog-tarief` statement. Verify: `occ humaniq:rules:audit` passes
      the seeded BBL contract at low.

## 2. Review

- [x] 2.1 Add `AwfReviewService` early-end review writing `awf-herziening` adjustments. Verify:
      unit test for a contract ended after six weeks with a hand-computed delta.
- [x] 2.2 Add the year-end extra-hours review. Verify: unit test for 24 contracted and 34 paid
      hours a week, and for 24 and 30 (no review).
- [x] 2.3 Add `nl-awf-herziening-uren-signaal`. Verify: the audit flags the seeded part-timer.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
