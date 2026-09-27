## 1. Declaration

- [ ] 1.1 Extend `lib/Settings/connections.json` with the rows of design D1. Verify: hydra gate
      116 passes; on a dev instance with integriq the rows appear in integriq's overview.
- [ ] 1.2 Dispatch `ConnectionStatusReportedEvent` for `shillinq-ledger` after glpost and netpay
      handoffs. Verify: unit test for a success and a failure report; nothing is dispatched
      when integriq is absent.

## 2. Pages

- [ ] 2.1 Add the `Integrations` page, its menu entry and the "Add integration" handler; retitle
      `IntegrationAccounts` to "Access grants". Verify: `npm run check:manifest` and
      `npm run lint` exit 0.
- [ ] 2.2 Add the `connectionStatus` and `connectionSettingsLabel` formatters. Verify: unit test
      for all seven statuses and an unknown one.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live look at the
      Integrations page with integriq installed and without it.
