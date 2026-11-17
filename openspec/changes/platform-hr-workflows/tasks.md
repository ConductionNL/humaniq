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

- [ ] 2.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live run of each
      enabled flow on a dev instance: complete the IT task and see `itProvisioned` ticked;
      leave a request undecided past the wait and see the HR task.

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
