## 1. Flows

- [x] 1.1 Declare "Indiensttreding" on `Onboarding` in `hr-onboarding.json`. Verify: the flow
      imports disabled through `occ maintenance:repair` and shows on `Flows`.
- [x] 1.1a Add the manager's onboarding steps (workplace and buddy, first month's programme,
      probation talk) to `Onboarding` in `hr-onboarding.json` and give them to the unit's
      manager in "Indiensttreding". Verify: `OnboardingDetail` lists the steps per owner (HR,
      IT, manager), as drawn on HuInwerken.
- [x] 1.2 Declare "Uitdiensttreding" on `Offboarding`. Verify: as 1.1.
- [x] 1.3 Declare "Verlofaanvraag blijft liggen" on `LeaveRequest`. Verify: as 1.1.
- [x] 1.4 Generalise `RepublishLoonrunFlow` to every declared humaniq flow. Verify: unit test
      that a second run changes nothing and a new flow is published.

## 2. Verification

- [x] 2.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live run of each
      enabled flow on a dev instance: complete the IT task and see `itProvisioned` ticked;
      leave a request undecided past the wait and see the HR task.
      Done 2026-11-25. Every scenario carries a reason-bearing `@e2e exclude` (gate 19). Live on a
      sandbox Nextcloud 34.0.6 (MariaDB 10.11, OpenRegister main 7997381d, humaniq development
      89e5e1bf): after `app:enable` the three flows were imported disabled (with the other
      humaniq flows); each was adopted and enabled through `POST /api/flows/{id}/adopt` and
      `PUT /api/flows/{id}`, and the background workers were run by hand.
      - Indiensttreding: creating an Onboarding raised "Account voor nieuwe medewerker regelen"
        for the admin group; completing it with `gedaan` ticked `itProvisioned` on the case and
        the run moved on to "Werkplek en buddy regelen" (assignee empty: the employee carried no
        `managerUserId`, so only the fallback group holds it; openregister#10565 territory).
      - Uitdiensttreding: creating an Offboarding raised "Bedrijfsmiddelen innemen", "Toegang
        intrekken" and "Exitgesprek voeren" in turn; completing each with `gedaan` ticked
        `assetsIngeleverd`, `toegangIngetrokken` and set `exitGesprekDone` to the completion date
        (2026-11-25); the run stopped at `end`.
      - Verlofaanvraag blijft liggen: submitting a LeaveRequest parked the run at the 5-day wait;
        with the run's `resume_at` moved to now (the sandbox cannot sit out five days) it re-read
        the request, still `submitted`, notified the `humaniq-hr` member and raised "Verlofaanvraag
        2026-12-17 tot 2026-12-18 opvolgen" for `humaniq-hr`. With that group empty, as on a fresh
        install, the step fails with "nobody currently holds any of them" instead of raising a
        task for nobody: an administrator must fill the group (or re-point it) before enabling
        the flow, as the flow's description says.

Build notes: 1.1 to 1.3 are declared and covered by `tests/Unit/Flow/HrWorkflowsDeclarationTest.php`;
their live import check (`occ maintenance:repair`, `Flows`) is part of 2.1. 1.4 is
`ShippedFlowPublisher`, run by `RepublishLoonrunFlow` after the Loonrun repair. 1.1a: `Onboarding` 0.5.0 gains
`werkplekEnBuddyGeregeld`, `eersteMaandProgrammaGedeeld` and `proeftijdgesprekGepland`
(register 0.63.0); "Indiensttreding" reads the case's employee after the IT step and gives the
three tasks in turn to `{{ employee.0.managerUserId }}` (admin as fallback candidate group), each
ticking its field; `OnboardingDetail` groups the checklist under HR, IT and Manager. Resolving a
performer from an item field is new in OpenRegister (ConductionNL/openregister#10565); until that
lands the manager tasks carry the placeholder as their assignee, so enable the flow only after it.
Covered by `HrWorkflowsDeclarationTest::testTheManagerStepsGoToTheEmployeesManager`.
