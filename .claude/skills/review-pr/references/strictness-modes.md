# Strictness Modes

Four modes govern which findings to post, how to handle uncertainty, and what verdict to submit.

---

## Quick

**Intent**: Fast gate-check for hotfixes, trivial tweaks, and re-reviews with minimal new changes. Move fast; only block on clear regressions.

**Include**: 🔴 Blockers only (definite issues)  
**Exclude**: 🟡 Concerns, 🟢 Minors — skip silently  
**Uncertain findings**: Post as 🟡 Concern, not 🔴 Blocker. In doubt, approve.

**Comment body style**: One short paragraph maximum. Skip impact/fix sections for concerns.

**Verdict**:
- APPROVE unless there is at least one definite 🔴 Blocker
- Approve by default when uncertain

---

## Standard

**Intent**: Everyday review that balances thoroughness with turnaround time. Catches real problems without noise.

**Include**: 🔴 Blockers + 🟡 Concerns  
**Exclude**: 🟢 Minors — skip silently  
**Uncertain findings**: Post as 🟡 Concern, not 🔴 Blocker.

**Comment body style**: Concise — one paragraph per comment maximum. State the problem and the fix; skip extended impact analysis unless the risk is non-obvious.

**Verdict**:
- REQUEST_CHANGES if any 🔴 Blockers present
- APPROVE if only 🟡 Concerns (or nothing found)

---

## Thorough

**Intent**: Comprehensive review for large PRs or those touching many subsystems. Leave no finding behind.

**Include**: 🔴 Blockers + 🟡 Concerns + 🟢 Minors  
**Uncertain findings**: Post as 🟡 Concern, not 🔴 Blocker.

**Comment body style**: Full body — problem statement, Impact section, Suggested fix section. Provide concrete code examples or approaches where helpful.

**Verdict**:
- REQUEST_CHANGES if any 🔴 Blockers present
- APPROVE if only 🟡 Concerns or 🟢 Minors

---

## Strict

**Intent**: Security, auth, RBAC, payment, or high-stakes code. Every doubt is a potential vulnerability; err on the side of blocking.

**Include**: 🔴 Blockers + 🟡 Concerns + 🟢 Minors  
**Uncertain findings**: Escalate to 🔴 Blocker (not 🟡 Concern).

**Comment body style**: Full body — problem statement, Impact section, Suggested fix section. Name the exact risk (e.g., privilege escalation, data leak, null-coercion bypass). Provide concrete examples.

**Verdict**:
- REQUEST_CHANGES if any 🔴 Blockers OR any 🟡 Concerns
- APPROVE only when zero blockers and zero concerns

---

## Mode Selection Signals

| Signal | Recommended mode |
|--------|-----------------|
| Security, auth, RBAC, payment, or permission code touched | **Strict** |
| PR size >500 additions OR >15 files changed | **Thorough** |
| Re-review with only small new commits (< 50 additions) | **Quick** |
| Hotfix, config tweak, or < 50 additions total | **Quick** |
| Anything else | **Standard** |

When signals conflict (e.g., large diff that also touches auth), choose the stricter mode.

---

## Wording Guidance by Mode

| Mode | Blocker phrasing | Concern phrasing |
|------|-----------------|-----------------|
| Quick | "This will break X — must fix before merge." | (Not posted) |
| Standard | "This will break X — must fix before merge." | "Worth addressing: …" |
| Thorough | "This will break X — must fix before merge. **Impact:** … **Suggested fix:** …" | "Worth addressing: … **Impact:** … **Suggested fix:** …" |
| Strict | "This is a potential security risk — must fix before merge. **Impact:** … **Suggested fix:** …" | Same as Thorough; any 🟡 blocks the PR |

---

## Submitting the review (Step 9)

### Fetch html_urls for inline comments

After Step 8 posts inline comments, fetch each comment's `html_url` so the verdict body can link to it:

```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/comments \
  --jq '[.[] | select(.pull_request_review_id == {COMMENT_REVIEW_ID}) | {id, html_url, body: .body[:60]}]'
```

Use these URLs as markdown links in the verdict body so the author can click straight from the verdict summary to each finding.

### Submit the verdict

```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/reviews \
  -X POST \
  -f commit_id="{HEAD_SHA}" \
  -f event="{APPROVE|REQUEST_CHANGES}" \
  -f body="{verdict body — see templates below}"
```

### Verdict body templates

One or two sentences max. Reference each finding with a markdown link:

- **REQUEST_CHANGES**: `"N blocker(s) require fixes — [Title](html_url)[, …]. [What checks out]."`
- **APPROVE**: `"No blockers. [Any notable observations with links to concern-level comments if present]."`

**Re-review variant**: also note how many old issues were resolved, how many remain open (with links to the still-open thread heads), and how many new issues were found (with links to the new inline comments). A markdown table per category (`| ID | Finding | Status / Resolved in |`) reads well when the count exceeds ~3 findings.

### Verdict overrides

Two conditions force `REQUEST_CHANGES` regardless of the mode-table verdict:

**CI override** — if `{failingRequiredChecks}` is non-empty:
> "Required CI checks are failing: [check-name], … — please fix before merging."

If only non-required checks are failing, do not override the verdict, but note them in the review body so the author is aware.

**Mechanical-gate override** — if `{PR_MAP}[PR_NUMBER].gateFindings` (from Step 5c) contains any failure on the protected list (gates 5, 7, 8, 9, 10, 11, 12, 13):
> "Mechanical gates failed: gate-{n} {name} — {reason}. See `hydra-gate-{name}` skill for the fix protocol."

Protected gates encode security and ADR-004 hard-rule violations — non-negotiable in any strictness mode. Failures on the unprotected list (1, 2, 3, 4, 6) follow normal mode rules — they are 🔴 findings that count toward the strictness verdict but don't independently override it.
