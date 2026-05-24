# Mechanical Gates Against PR Diff

Full protocol for Step 5c. Runs the 13 hydra-gates against the PR head SHA, scoped to the
PR's diff. Pre-flags deterministic findings so the analysis subagent in Step 6 can focus
on semantic issues.

---

## Why pre-flag mechanically?

The agent is good at semantic analysis but inconsistent on syntactic checks (regex-style
scans for forbidden patterns, attribute presence, label props, file location). The gate
script gets this right deterministically — let it. Brief the agent NOT to re-discover the
same findings (see [analysis-brief.md](analysis-brief.md)).

---

## Command

For each PR in `{PR_LIST}` (run **sequentially**, not parallel — the git worktrees use the
same clone):

```bash
bash scripts/run-gates-on-pr.sh \
    {OWNER} {REPO} {PR_NUMBER} {BASE_REF} \
    > /tmp/pr-{PR_NUMBER}-gates.log 2>&1
GATES_EXIT=$?
```

---

## Outcomes

| Exit | Meaning | Action |
|------|---------|--------|
| `0` | All 13 gates passed | Set `{PR_MAP}[PR_NUMBER].gateFindings = []` and `gateStatus = "passed"` |
| `1`–`13` | That many gates failed | Parse `[gate-N] <name>: FAIL — <reason>` lines from the log; per-gate detail is in `/tmp/hydra-gate-<name>.log`. Store as `gateFindings: [{n, name, reason, file_lines, fix_skill}]`, set `gateStatus = "failed"` |
| `97` | Worktree creation failed | `gateStatus = "skipped"`, note in brief; do NOT block |
| `98` | Could not fetch PR head | `gateStatus = "skipped"`, note in brief |
| `99` | CWD is not a clone of `{OWNER}/{REPO}` | `gateStatus = "skipped"`, note in brief; suggest user run review-pr from inside a clone |

---

## Protected gate list — failures force `REQUEST_CHANGES`

These are security / ADR-004 hard-rule violations, non-negotiable in any strictness mode:

- Gate 5 — route-auth (NC middleware reachability)
- Gate 7 — no-admin-IDOR (OWASP A01)
- Gate 8 — unsafe-auth-resolver (CWE-863 fail-open)
- Gate 9 — semantic-auth (annotation/body mismatch)
- Gate 10 — initial-state (CSP-hardened breakage + ADR-004)
- Gate 11 — admin-router (security regression)
- Gate 12 — nc-input-labels (WCAG 1.3.1 / 4.1.2)
- Gate 13 — modal-isolation (ADR-004)

Failures on the unprotected list (1, 2, 3, 4, 6) still surface as 🔴 blockers in the
analysis brief, but Step 9's strictness rules apply normally.
