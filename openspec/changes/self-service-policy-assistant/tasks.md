## 1. Sources

- [ ] 1.1 Add read-only `x-openregister-mcp` to `Announcement`, `LeaveType`, the CAO display
      schema and `Normfunctie`. Verify: the MCP tool list shows four new search and get tools and
      still none for `LeaveBalance`, `SickLeaveCase`, `Payslip` or `Employee`; the
      `humaniq-mcp-surface` refusal tests still pass.

## 2. Assistant

- [ ] 2.1 Add the HR-vraagbaak agent definition in the form hermiq loads. Verify: on a dev
      instance with hermiq the agent appears in the companion's picker.
- [ ] 2.2 Preselect it on Mijn HR routes. Verify: `npm run lint` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live question about
      the expense policy (answered with the policy cited) and one about the asker's own holiday
      hours (answered with the `MijnVerlof` link).
