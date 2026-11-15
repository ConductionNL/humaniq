## 1. Declaration

- [x] 1.1 Extend `lib/Settings/connections.json` with the rows of design D1. Verify: hydra gate
      116 passes; on a dev instance with integriq the rows appear in integriq's overview.
      - gate 116 (`check_connections_declaration.js`) passes with 0 findings; the live look in
        integriq's overview is still to do.
- [x] 1.2 Dispatch `ConnectionStatusReportedEvent` for `shillinq-ledger` after glpost and netpay
      handoffs. Verify: unit test for a success and a failure report; nothing is dispatched
      when integriq is absent.
      - `Service\Connection\ConnectionReporter`: `configured` when shillinq took or already held
        the journal entry or payment run, `error` with the reason when shillinq refused it,
        `unavailable` when shillinq is absent. A run humaniq refuses itself (missing period,
        missing IBAN) never reached shillinq and reports nothing.

## 2. Pages

- [x] 2.1 Add the `Integrations` page, its menu entry and the "Add integration" handler; retitle
      `IntegrationAccounts` to "Access grants". Verify: `npm run check:manifest` and
      `npm run lint` exit 0.
      - `src/manifest.d/platform-integrations-catalogue.json`: index page at
        `/configuratie/koppelingen` over `integriq/app_connection`, scoped by `config.filter`
        to `app: humaniq`, `requiresApp` integriq; a Configuratie entry hidden by
        `visibleIf.appInstalled: integriq`. `openIntegriqConnections`
        (`src/services/connectionRegistry.js`) leaves for
        `/apps/integriq/connections?app=humaniq&link=1`. `IntegrationAccounts` page and menu
        entry now read "Access grants", route unchanged. check:manifest, manifest parity,
        check:l10n and lint pass.
- [x] 2.2 Add the `connectionStatus` and `connectionSettingsLabel` formatters. Verify: unit test
      for all seven statuses and an unknown one.
      - Both are built-ins of @conduction/nextcloud-vue 2.89.2 (`BUILT_IN_FORMATTERS`), which
        CnAppRoot provides to every page, so humaniq carries no copy;
        `tests/js/integrationsPage.test.mjs` checks the seven labels, an unknown status
        passing through, and the settings label.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live look at the
      Integrations page with integriq installed and without it.
