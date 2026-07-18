# Tasks — irma-digid-auth

> This change adds documentation and binding anti-requirements only. Verify
> against HEAD, not this brief: re-confirm that `mijn-hr-self-service`'s
> `MijnLoonstroken` is still read-only and that no self-service write over
> `Employee.iban`/`tenaamstelling` exists elsewhere before treating
> REQ-AUTH-003's trigger condition as unmet.

- [ ] 1. Docs: add an "Authentication" section to `README.md` (or `docs/`)
  stating that DigiD, Yivi and eHerkenning are configured at the Nextcloud
  instance level via `user_saml` (SAML 2.0, Logius DigiD/eHerkenning broker)
  and/or `user_oidc` (OIDC, Yivi-compatible bridge), entirely outside hrmq's
  install footprint, per REQ-AUTH-001/-002
- [ ] 2. Docs: state explicitly that once either backend authenticates a
  person, hrmq requires nothing further — no hrmq-side setup, certificate,
  or configuration step exists or is needed — per REQ-AUTH-002
- [ ] 3. Docs: record the superseded draft (`spec/irma-digid-auth`) as idea
  source only, listing which of its ideas were rejected outright (federation
  stack, fraud scoring, hash-chained audit) versus deferred with a named
  trigger (step-up) per REQ-AUTH-001/-003
- [ ] 4. Docs: state the REQ-AUTH-003 revisit condition verbatim (a genuine
  sensitive self-service write action is proposed AND a concrete
  NC-exposed assurance signal is verified) so a future proposal can cite it
  directly per REQ-AUTH-003
- [ ] 5. Verify: confirm via `grep` against current HEAD that no
  `IdentityProvider`/`Session`/`AuthEvent`/`FraudSignal`/`AttributeMapping`
  schema, route, controller, or service exists anywhere in the repo (this
  change must not introduce any) per REQ-AUTH-001
- [ ] 6. Verify: re-check `openspec/specs/mijn-hr-self-service/spec.md`
  (`MijnLoonstroken` read-only) and `openspec/specs/
  payroll-sepa-netpay-shillinq/spec.md` (`iban`/`tenaamstelling`
  payroll-authored) are still accurate at implementation time — if either
  has changed to add a self-service write, REQ-AUTH-003's trigger condition
  may already be met and this change's disposition should be reopened
  before merging documentation that says otherwise

Acceptance criteria (plain reminders, not tasks):
- the diff contains no `lib/Settings/register.d/*.json`, no
  `src/manifest.json`, no `lib/Controller/`, `lib/Service/`, or
  `appinfo/routes.php` changes — verify by inspecting the actual diff at
  implementation time
- the documentation names `user_saml` and `user_oidc` by their real
  Nextcloud app ids, not invented names
- REQ-AUTH-003 is stated as a condition to check, never as a commitment to
  build step-up later without re-verifying the condition first
- i18n: any new documentation strings are plain English prose (docs are not
  subject to the manifest i18n-key convention, ADR-007 governs UI strings
  only)
