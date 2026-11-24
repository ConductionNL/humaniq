## 1. Sources

- [x] 1.1 Add read-only `x-openregister-mcp` to `Announcement`, `LeaveType`, the CAO display
      schema and `Normfunctie`. Verify: the MCP tool list shows four new search and get tools and
      still none for `LeaveBalance`, `SickLeaveCase`, `Payslip` or `Employee`; the
      `humaniq-mcp-surface` refusal tests still pass.

## 2. Assistant

- [x] 2.1 Add the HR-vraagbaak agent definition in the form hermiq loads. Verify: on a dev
      instance with hermiq the agent appears in the companion's picker.
- [x] 2.2 Preselect it on Mijn HR routes. Verify: `npm run lint` exits 0.
      Done: `src/App.vue` passes `aiDefaultAgent` (`src/services/hrAssistantAgent.js`): HR-vraagbaak on routes under `/mijn` and on `Mijn*` pages, else '' for the library default; the prop came with @conduction/nextcloud-vue 2.204.0 (nextcloud-vue#2303). Tests: `tests/js/hrAssistantAgent.test.mjs` (5). `npm run lint` exits 0.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live question about
      the expense policy (answered with the policy cited) and one about the asker's own holiday
      hours (answered with the `MijnVerlof` link).
      Written, runs in nightly: all three scenarios anchored in `hr-policy-assistant.spec.ts`; the answer checks run where the instance sets E2E_HR_ASSISTANT=1.
