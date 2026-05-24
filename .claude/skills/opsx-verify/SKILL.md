---
name: opsx-verify
description: Verify implementation matches change artifacts before archiving
metadata:
  category: Workflow
  tags: [workflow, verify, experimental]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — verifying implementation against specs and running tests needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Verify that an implementation matches the change artifacts (specs, tasks, design).

## Input modes

- **Per-change** (default) — `/opsx-verify <change-name>` or `/opsx-verify` (prompts). Verifies a single OpenSpec change against its artifacts. This is the mode used during normal feature work, before archiving.
- **Per-app retrofit DoD** — `/opsx-verify --app <slug>`. Runs the retrofit "definition of done" check across an entire retrofitted app. See [references/app-mode.md](references/app-mode.md). Required by the [retrofit playbook](../../../../.github/docs/claude/retrofit.md) "When the retrofit is done" section.

**Mode dispatch:**

- If the input starts with `--app `, follow [references/app-mode.md](references/app-mode.md) (§A1–A6); skip the per-change Steps 1–11.
- Otherwise follow the per-change Steps 1–11 below.
- Disambiguation: if the user passes a single token that matches an app slug (e.g. `openregister`), ask once via AskUserQuestion whether they meant `--app openregister` (retrofit DoD) or the change literally named `openregister` (likely doesn't exist). Default to `--app` for the canonical Nextcloud app slugs (`openregister`, `procest`, `pipelinq`, `decidesk`, `docudesk`, `openconnector`, `nldesign`, `mydash`, `softwarecatalog`, `larpingapp`, `zaakafhandelapp`, `opencatalogi`).

---

## Per-change mode

The remaining steps describe the per-change verify. Skipped when `--app` is specified.

### Steps

1. **If no change name provided, prompt for selection**

   Run `openspec list --json` to get available changes. Use the **AskUserQuestion tool** to let the user select.

   Show changes that have implementation tasks (tasks artifact exists). Include the schema used for each change if available. Mark changes with incomplete tasks as "(In Progress)".

   **IMPORTANT**: Do NOT guess or auto-select a change. Always let the user choose.

2. **Check status to understand the schema**

   ```bash
   openspec status --change "<name>" --json
   ```

   Parse the JSON to understand:
   - `schemaName`: The workflow being used (e.g., "spec-driven")
   - Which artifacts exist for this change

3. **Get the change directory and load artifacts**

   ```bash
   openspec instructions apply --change "<name>" --json
   ```

   This returns the change directory and context files. Read all available artifacts from `contextFiles`.

   **Additionally, load optional artifacts if present:**
   - `openspec/changes/<name>/test-plan.md` — pre-defined test cases mapped to spec scenarios; use as the primary oracle for scenario coverage and testing
   - `openspec/changes/<name>/contract.md` — formal API contract; if present, it is the authoritative interface definition and takes precedence over design.md for API verification

4. **Initialize verification report structure**

   Create a report structure with three dimensions:
   - **Completeness**: Track tasks and spec coverage
   - **Correctness**: Track requirement implementation and scenario coverage
   - **Coherence**: Track design adherence and pattern consistency

   Each dimension can have CRITICAL, WARNING, or SUGGESTION issues.

5. **Verify Completeness**

   **Task Completion**:
   - If tasks.md exists in contextFiles, read it
   - Parse checkboxes: `- [ ]` (incomplete) vs `- [x]` (complete)
   - Count complete vs total tasks
   - If incomplete tasks exist:
     - Add CRITICAL issue for each incomplete task
     - Recommendation: "Complete task: <description>" or "Mark as done if already implemented"
   - **Sync already-complete tasks to GitHub** (only if plan.json exists): For every task that is already `[x]` in tasks.md but whose `plan.json` status is not `"done"`, treat it as just-completed and run the full GitHub sync below. If plan.json does not exist, skip all GitHub sync steps silently.
   - If browser or API tests (step 8) verify that acceptance criteria for an incomplete task are met:
     - Mark those criteria as `[x]` in tasks.md
     - If ALL criteria of that task are now checked, mark the task itself as `[x]` in tasks.md
   - **For every task marked `[x]` in tasks.md** (whether already complete before this run, or just completed above), if plan.json exists and that task's `status` in plan.json is not `"done"`:
     - **Check off this task and ALL its sub-checkboxes in the tracking issue body**:
       - Fetch the issue body once (batch all task updates before writing back)
       - For each task to check off: find the parent task line by matching its title (e.g., `- [ ] **1.1 Task title**`), change it to `- [x]`; then scan every immediately following line — for each line starting with `  - [ ]` (2-space indent), change it to `  - [x]`; stop scanning at any line that is NOT an indented sub-checkbox (blank line, new parent checkbox, section header, etc.)
       - **MCP (preferred):** `get_issue` → `{owner, repo, issue_number: <tracking_issue>}` → apply the above changes for all tasks → `update_issue` → `{owner, repo, issue_number: <tracking_issue>, body: <updated_body>}`
       - **CLI (fallback):** `gh issue view <tracking_issue> --repo <repo> --json body --jq '.body'` → apply the above changes for all tasks → `gh issue edit <tracking_issue> --repo <repo> --body "<updated_body>"`
       - **IMPORTANT**: Batch all updates into a single `update_issue` call — fetch the body once, apply all checkbox changes, then write it back once.
     - Update `plan.json`: set `"status": "done"` for that task
     - **Do NOT close the issue** — the issue will be closed when the PR is merged or during archive

   **Spec Coverage**:
   - If delta specs exist in `openspec/changes/<name>/specs/`:
     - Extract all requirements (marked with "### Requirement:")
     - For each requirement, search codebase for keywords and assess if implementation likely exists
     - If requirements appear unimplemented: Add CRITICAL: "Requirement not found: <name>" with recommendation "Implement requirement X: <description>"

6. **Verify Correctness**

   **Requirement Implementation Mapping**:
   - For each requirement from delta specs:
     - Search codebase for implementation evidence; if found, note file paths and line ranges
     - Assess if implementation matches requirement intent
     - If divergence detected: Add WARNING: "Implementation may diverge from spec: <details>" with recommendation "Review <file>:<lines> against requirement X"

   **Scenario Coverage**:
   - **If test-plan.md is loaded**: use the TCs as the canonical scenario checklist. For each TC:
     - Verify the acceptance criteria are met in the implementation
     - Note the TC's `test command` field — use it in step 8 to run the right test type
     - If a TC's expected result appears unmet: Add WARNING: "TC not satisfied: TC-N <title>"
   - **If no test-plan.md**: fall back to scanning spec scenarios directly:
     - For each scenario in delta specs (marked with "#### Scenario:"):
       - Check if conditions are handled in code
       - If scenario appears uncovered: Add WARNING: "Scenario not covered: <scenario name>"

7. **Verify Coherence**

   **Contract Adherence** (checked first if contract.md exists):
   - If contract.md is loaded: it is the authoritative interface definition — verify against it before design.md
     - For each declared endpoint: verify it exists in code with the correct method, path, and auth requirement
     - For each schema: verify request/response fields match the contract
     - For each error code: verify the declared HTTP status and condition are implemented
     - If an endpoint, schema field, or error code is missing or diverges:
       - Add CRITICAL: "Contract violation: <endpoint/schema/field> does not match contract.md"
       - Recommendation: "Implement contract as specified — contract is the cross-team interface agreement"

   **Design Adherence**:
   - If design.md exists in contextFiles:
     - Extract key decisions (look for sections like "Decision:", "Approach:", "Architecture:")
     - Verify implementation follows those decisions
     - If contradiction detected: Add WARNING: "Design decision not followed: <decision>" with recommendation "Update implementation or revise design.md to match reality"
   - If neither contract.md nor design.md: Skip coherence check, note "No contract.md or design.md to verify against"

   **Code Pattern Consistency**:
   - Review new code for consistency with project patterns (file naming, directory structure, coding style)
   - If significant deviations found: Add SUGGESTION: "Code pattern deviation: <details>" with recommendation "Consider following project pattern: <example>"

   **Frontend Pattern Adherence** (run if the change touched any `.vue`/`.js`/`.ts` files in `src/`):

   Run the four mechanical gates from [references/frontend-and-testing.md](references/frontend-and-testing.md) — they mirror Hydra gates 10–13 and map to ADR-004 hard rules. Each is CRITICAL when violated:
   1. **Initial state, not DOM** (gate-10) — `getElementById(...).dataset` reads
   2. **No admin in vue-router** (gate-11) — admin components routed
   3. **NcSelect labels** (gate-12) — missing `inputLabel`/`ariaLabelCombobox`
   4. **Modal/dialog file isolation** (gate-13) — inline `<NcModal>`/`<NcDialog>`

   **Test Coverage**:
   - For each new PHP service/controller file, check if a corresponding test file exists in `tests/Unit/` or `tests/unit/`
   - For each new Vue component, check if a test file exists (if project has Jest/Vitest)
   - If a new service has NO test: Add CRITICAL: "Missing unit test for <ServiceName>" with recommendation "Create tests/Unit/Service/<ServiceName>Test.php with at least 3 test methods"
   - If tests exist but cover fewer than 3 methods: Add WARNING: "Insufficient test coverage for <ServiceName>"

   **Documentation**:
   - Check if the PR updates README.md or docs/ with new feature description
   - Check if new API endpoints are documented
   - If no documentation found: Add WARNING: "No documentation for new feature" with recommendation "Add feature description to README.md and document new API endpoints"

8. **Ask about API and browser testing**

   After the code-level verification, use **AskUserQuestion** to ask:
   "Would you also like to run API and/or browser tests against the specs and implementation?"

   Options:
   - **Both API and browser tests** — Run API tests first, then browser tests
   - **API tests only** — Test API endpoints against spec requirements
   - **Browser tests only** — Test UI behavior against spec scenarios
   - **Skip testing** — Continue with code-level findings only

   Detailed curl commands, browser-MCP setup, and per-step recipes live in [references/frontend-and-testing.md](references/frontend-and-testing.md) — API Testing and Browser Testing sections. Use them as the playbook for whichever option the user selects, then add findings as CRITICAL (broken/missing), WARNING (degraded/non-compliant), or SUGGESTION (polish).

9. **Generate Verification Report**

   **Summary Scorecard**:
   ```
   ## Verification Report: <change-name>

   ### Summary
   | Dimension    | Status           |
   |--------------|------------------|
   | Completeness | X/Y tasks, N reqs|
   | Correctness  | M/N reqs covered |
   | Coherence    | Followed/Issues  |
   | API Tests    | Passed/Failed/Skipped |
   | Browser Tests| Passed/Failed/Skipped |
   ```

   **Issues by Priority**:

   1. **CRITICAL** (Must fix before archive):
      - Incomplete tasks
      - Missing requirement implementations
      - Failed API/browser tests
      - Each with specific, actionable recommendation

   2. **WARNING** (Should fix):
      - Spec/design divergences
      - Missing scenario coverage
      - Each with specific recommendation

   3. **SUGGESTION** (Nice to fix):
      - Pattern inconsistencies
      - Minor improvements
      - Each with specific recommendation

10. **Fix loop — resolve issues and re-verify**

    **If CRITICAL or WARNING issues found:**
    - Display the full report
    - Use **AskUserQuestion** to ask: "Found issues. Would you like me to fix them?"
      - **Yes, fix all issues** — Fix all CRITICAL and WARNING issues
      - **Yes, fix critical only** — Fix only CRITICAL issues
      - **No, leave as-is** — Skip fixing, proceed to final assessment

    **If fixing:**
    - Work through each issue, making the necessary code changes
    - After all fixes are applied, **re-run verification from step 5** (skip steps 1-4, reuse loaded context)
    - Show updated report with resolved issues marked
    - If new issues are found during re-verify, repeat this fix loop
    - Continue looping until no CRITICAL/WARNING issues remain or the user chooses to stop

11. **Final assessment and archive prompt**

    **FIRST: Re-check task completion** — regardless of other findings, re-read tasks.md and count `- [ ]` items:
    - If ANY tasks are still `- [ ]`: **do NOT offer archive**. Show:
      ```
      ⚠️ N task(s) still incomplete — archive is blocked until all tasks are done:
      - Task X: <description> (incomplete criteria: ...)
      ```
      End the session without offering archive.

    **If all tasks `[x]` AND CRITICAL issues remain (user chose not to fix):**
    - "X critical issue(s) remain. Recommend fixing before archiving."
    - Do NOT prompt for archive

    **If all tasks `[x]` AND only SUGGESTION issues or all clear:**
    - Display: "All checks passed. Implementation matches specs."
    - If plan.json exists, update the pipeline progress comment on the issue (search for `## Pipeline Progress`, update via PATCH if found, create if not):
      ```markdown
      ## Pipeline Progress

      | Stage | Status | Details |
      |-------|--------|---------|
      | Implementation | ✓ Complete | All N tasks done |
      | Quality Checks | ✓ Pass | lint, phpcs, phpstan clean |
      | Verification | ✓ Pass | Completeness, correctness, coherence |
      | Archive | ready | |

      *Updated: YYYY-MM-DD HH:MM UTC*
      ```
    - Also add a brief comment:
      - **MCP (preferred):** GitHub MCP `add_issue_comment` → `{owner, repo, issue_number: <tracking_issue>, body: "✓ Verified by /opsx-verify — all checks passed"}`
      - **CLI (fallback):** `gh issue comment <tracking_issue> --repo <repo> --body "✓ Verified by /opsx-verify — all checks passed"`
    - Use **AskUserQuestion** to ask: "Ready to archive this change?"
      - **Yes, archive now** — Execute `/opsx-archive` for this change
      - **Sync specs first, then archive** — Execute `/opsx-sync` then `/opsx-archive`
      - **No, not yet** — End the session

    **If all tasks `[x]` AND only WARNING issues remain (user chose not to fix):**
    - "No critical issues. Y warning(s) noted."
    - Use **AskUserQuestion** to ask: "Archive this change with noted warnings?"
      - **Yes, archive with warnings** — Execute `/opsx-archive` for this change
      - **Sync specs first, then archive** — Execute `/opsx-sync` then `/opsx-archive`
      - **No, I'll fix them first** — End the session

---

## Verification Heuristics

- **Completeness**: Focus on objective checklist items (checkboxes, requirements list)
- **Correctness**: Use keyword search, file path analysis, reasonable inference — don't require perfect certainty
- **Coherence**: Look for glaring inconsistencies, don't nitpick style
- **Testing**: Test against spec scenarios, not exhaustive edge cases
- **False Positives**: When uncertain, prefer SUGGESTION over WARNING, WARNING over CRITICAL
- **Actionability**: Every issue must have a specific recommendation with file/line references where applicable

## Graceful Degradation

- If only tasks.md exists: verify task completion only, skip spec/design checks
- If tasks + specs exist: verify completeness and correctness, skip design
- If full artifacts: verify all three dimensions
- Always note which checks were skipped and why

## Fix Loop Behavior

- Re-verification after fixes reuses the already-loaded context (no need to re-read artifacts)
- Only re-verify the dimensions that had issues (skip clean dimensions)
- Track which issues were resolved vs newly introduced
- Maximum 3 fix-verify cycles before suggesting the user take over manually

## Output Format

Use clear markdown with:
- Table for summary scorecard
- Grouped lists for issues (CRITICAL/WARNING/SUGGESTION)
- Code references in format: `file.ts:123`
- Specific, actionable recommendations
- No vague suggestions like "consider reviewing"

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
