## 1. Flows

- [ ] 1.1 Declare "Indiensttreding" on `Onboarding` in `hr-onboarding.json`. Verify: the flow
      imports disabled through `occ maintenance:repair` and shows on `Flows`.
- [ ] 1.2 Declare "Uitdiensttreding" on `Offboarding`. Verify: as 1.1.
- [ ] 1.3 Declare "Verlofaanvraag blijft liggen" on `LeaveRequest`. Verify: as 1.1.
- [ ] 1.4 Generalise `RepublishLoonrunFlow` to every declared humaniq flow. Verify: unit test
      that a second run changes nothing and a new flow is published.

## 2. Verification

- [ ] 2.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live run of each
      enabled flow on a dev instance: complete the IT task and see `itProvisioned` ticked;
      leave a request undecided past the wait and see the HR task.
