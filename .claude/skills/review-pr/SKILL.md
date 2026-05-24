---
name: review-pr
description: Review one or more GitHub Pull Requests — detects re-reviews, asks strictness (Quick/Standard/Thorough/Strict), posts 🔴/🟡/🟢 inline comments, optionally tests PR changes locally, then submits APPROVE or REQUEST_CHANGES. Accepts multiple PRs for parallel batch review.
metadata:
  category: Delivery
  tags: [github, pull-request, code-review, inline-comments, re-review, batch]
---

# PR Review

Fetches one or more GitHub PRs, checks for prior reviews, determines strictness,
runs deep analysis (in parallel when multiple PRs), posts each finding as a separate
inline comment, then submits a formal review per PR.

**Input**: `/review-pr <PR URL or number> [<PR URL or number> ...]`

Single PR: `/review-pr https://github.com/org/repo/pull/123`
Batch: `/review-pr 123 456` or `/review-pr https://github.com/org/repo/pull/123 https://github.com/org/repo/pull/456`

---

## Model Recommendation

This skill runs deep analysis and reasons about diffs, null semantics, SQL parity,
and test coverage. Haiku is not sufficient for the orchestrator.

**Check the active model** (shown as "You are powered by the model named…"):

- **Haiku**: stop immediately and tell the user:
  > "PR review requires Sonnet or Opus — switch models and re-run."
- **Sonnet or Opus**: proceed. Store as `{ORCHESTRATOR_MODEL}`.

---

## Step 0: Select Analysis Agent Model

**Single PR**: analysis sub-agent inherits `{ORCHESTRATOR_MODEL}`. Skip this step.

**Batch mode only** — ask using AskUserQuestion:

> **"Which model should the {N} parallel analysis agents use?"**
>
> | Model | Speed | Quota | Best for |
> |-------|-------|-------|----------|
> | **Sonnet** *(recommended)* | Balanced | Moderate | Reliable review depth — catches subtle bugs, null-safety issues, logic errors |
> | **Opus** | Slowest | High | Deepest analysis — for security-sensitive or architecturally complex PRs |
> | **Haiku** | Fastest | Low | Not recommended — shallow analysis misses subtle issues in code review |
>
> Sonnet is the default. Haiku saves quota but produces noticeably weaker findings on
> diff analysis. Opus is best when any PR in the batch is security-sensitive or large.

Store as `{AGENT_MODEL}`. Pass `model: "{AGENT_MODEL}"` when spawning each analysis
sub-agent in Step 6.

---

## Step 1: Parse PR References

Split `$ARGUMENTS` on whitespace to get one or more PR references. For each token:

- Full URL `https://github.com/<owner>/<repo>/pull/<n>` → parse directly
- `<n>` only → infer repo from `git remote get-url origin`
- `<n> <owner>/<repo>` → use as given

Store as `{PR_LIST}`: an array of `{OWNER, REPO, PR_NUMBER}` objects.

**Single PR**: set `{BATCH_MODE}=false`, use `{PR_LIST}[0]` as `{OWNER}`, `{REPO}`, `{PR_NUMBER}` throughout — the rest of the flow reads identically.

**Multiple PRs**: set `{BATCH_MODE}=true`. Steps 2–5 run for each PR; Step 4 consolidates into one strictness question; Step 6 runs analysis agents in parallel; Step 7 presents a combined summary before one batch post confirmation.

For every PR in `{PR_LIST}`, store its resolved metadata as `{PR_MAP}[PR_NUMBER]` (owner, repo, title, additions, deletions, changedFiles, headSha, isRereview, lastReview, sensitivity, strictnessMode, findings, etc.) — this map is the per-PR scratchpad used in Steps 2–9.

---

## Step 2: Fetch PR Metadata and Detect Re-review

```bash
gh api user --jq '.login'   # store as {CURRENT_USER} (once, shared across all PRs)
```

**For each PR in `{PR_LIST}`** (run in parallel for batch mode):

```bash
gh pr view {PR_NUMBER} --repo {OWNER}/{REPO} \
  --json title,body,author,state,additions,deletions,changedFiles,\
baseRefName,headRefName,url,mergeable,reviewDecision

gh pr view {PR_NUMBER} --repo {OWNER}/{REPO} \
  --json files --jq '.files[] | "\(.additions)+ \(.deletions)- \(.path)"'

gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER} --jq '.head.sha'
# store per PR as {PR_MAP}[PR_NUMBER].headSha

gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/reviews \
  --jq '[.[] | {id:.id, user:.user.login, state:.state, submitted_at:.submitted_at}]'
```

**Merged / closed PR check** (per PR): read the `state` field from the `gh pr view` output.

- `MERGED` → PR was merged; reviewing it will not block or unblock anything.
- `CLOSED` (not merged) → PR was abandoned.
- `OPEN` → normal; continue.

After fetching metadata, collect every PR whose `state` is not `OPEN` into `{NON_OPEN_PRS}`. If non-empty, ask the user before proceeding: for a single PR confirm "already {state} — review anyway?"; for batch mode show a table and ask "Review all / Skip merged-closed / Select". Remove user-skipped PRs from `{PR_LIST}`; if `{PR_LIST}` is now empty, stop.

**Re-review detection** (per PR): filter reviews by `{CURRENT_USER}`. If any exist, store
the most recent as `{PR_MAP}[PR_NUMBER].lastReview` (id, state, submitted_at).
Set `{PR_MAP}[PR_NUMBER].isRereview=true`.

**CI check status** (per PR): fetch check runs for `{HEAD_SHA}` and required checks for the base branch.

⚠️ `gh pr checks --json` silently returns `[]` in some environments and must NOT be used as the primary source. Always fetch directly from the check-runs API. The endpoint also returns BOTH old runs from the base commit AND new runs from the PR head — dedupe by name + latest `completed_at` to get the *current* status:

```bash
gh api repos/{OWNER}/{REPO}/commits/{HEAD_SHA}/check-runs \
  --jq '[.check_runs[] | {name, status, conclusion, completed_at}]
        | group_by(.name) | map(max_by(.completed_at // ""))
        | [.[] | {name, state:.status, conclusion}]'
# store as {PR_MAP}[PR_NUMBER].checkRuns

gh api repos/{OWNER}/{REPO}/branches/{BASE_REF}/protection \
  --jq '.required_status_checks.contexts // []' 2>/dev/null || echo '[]'
# store as {PR_MAP}[PR_NUMBER].requiredChecks (empty array if branch protection is not configured)
```

Branch protection: also check the ruleset API (`GET /repos/{owner}/{repo}/rulesets`) before concluding the branch is unprotected — ConductionNL uses rulesets; the classic protection endpoint returns 404 even when rulesets are in force.

Derive `{PR_MAP}[PR_NUMBER].failingChecks`: entries from `checkRuns` where
`conclusion == "failure"` or `conclusion == "timed_out"` or `state == "failure"`.
Derive `{PR_MAP}[PR_NUMBER].failingRequiredChecks`: subset of `failingChecks` whose
`name` appears in `requiredChecks`. Store both arrays.

---

## Step 2a: PR Quality Pre-check

For each PR in `{PR_LIST}` (after Step 2 metadata, before strictness): validate title and
description quality per [references/pr-quality-precheck.md](references/pr-quality-precheck.md).

If a non-trivial PR has a missing/stub description, post a 🟡 top-level comment requesting
one and use AskUserQuestion to ask whether to stop or continue. If only the title is a
stub, note as 🟢 Minor in the analysis brief. For batch mode, aggregate flagged PRs and
ask once. Branch-promotion PRs are an exception — see the reference for the alternative
context-gathering path.

---

## Step 3: Fetch Re-review Context (re-review only)

Skip this step unless `{IS_REREVIEW}` is true.
Follow the full fetch protocol in [references/re-review-context.md](references/re-review-context.md). Store results as `{PREV_REVIEW_COMMENTS}`, `{PREV_REVIEW_VERDICT}`, `{COMMITS_SINCE_REVIEW}`.

**This step contains an early-exit gate.** If there are no new commits, no new PR-level comments, and no replies to your prior review comments since `{LAST_REVIEW.submitted_at}`, the protocol uses AskUserQuestion to recommend stopping (with an option to continue anyway). This gate fires BEFORE Step 4 — do not ask for strictness mode until it has been checked.

---

## Step 4: Recommend and Confirm Strictness Mode

Read [references/strictness-modes.md](references/strictness-modes.md) for the full
behaviour of each mode.

**Recommend a mode per PR** based on PR metadata (use `{IS_SECURITY_SENSITIVE}` from Step 4a):

| Signal | Recommended mode |
|--------|-----------------|
| `{IS_SECURITY_SENSITIVE}` is true (auth, RBAC, permissions, …) | **Strict** |
| PR size >500 additions OR >15 files changed | **Thorough** |
| Re-review with only small new commits | **Quick** |
| Hotfix, config tweak, or <50 additions | **Quick** |
| Anything else | **Standard** |

Use AskUserQuestion to confirm. For single PR: "How strictly should I review PR #{PR_NUMBER} — {title}? I recommend **{RECOMMENDED_MODE}** based on {brief reason}." For batch: if all recommendations match, ask once; if they differ, show a per-PR table and offer "Use recommended per PR" or a single override mode (Quick / Standard / Thorough / Strict).

**This step is mandatory — never skip it, even for re-reviews or tiny fix commits.** The recommendation changes based on signals; the confirmation question does not get omitted.

Store `{PR_MAP}[PR_NUMBER].strictnessMode` per PR (or the override for all PRs).

---

## Step 4a: Classify PR Sensitivity

Scan the changed file list from Step 2 for security-sensitive signals. Check for any of:

- Auth, OAuth, session, or token handling
- RBAC, permission checks, or access-control logic
- CI/CD workflows, deploy scripts, or Dockerfile changes
- API keys, secrets, service accounts, or machine identities
- SSO/SAML/OIDC integration

Store `{IS_SECURITY_SENSITIVE}=true` if any signal is present, otherwise `false`.
Store `{SECURITY_SIGNALS}` as a short list of the matched signals (e.g. `["RBAC", "permission checks"]`).

This classification is used by both Step 4b (persistence audit offer) and Step 4 when
computing the strictness recommendation — a security-sensitive PR always recommends Strict.

---

## Step 4b: Offer Persistence Audit (security PRs only)

Skip this step unless `{IS_SECURITY_SENSITIVE}` is true.

Read [references/persistence-audit-integration.md](references/persistence-audit-integration.md) for the full persistence audit offer protocol, severity mapping, and posting instructions.

Store the choice as `{PERSISTENCE_AUDIT_ACTION}` (inline | top-level | show-first | skip).

---

## Step 5: Stage the Diff

For each PR in `{PR_LIST}` (parallel in batch mode), save the diff for analysis:

For a **first review**: save the full PR diff.

```bash
gh pr diff {PR_NUMBER} --repo {OWNER}/{REPO} \
  > /tmp/pr-{PR_NUMBER}-diff.txt 2>/dev/null
```

**Mega-PR fallback** (>~300 files): `gh pr diff` and `Accept: application/vnd.github.v3.diff` both return HTTP 406 `too_large`. `gh pr view --json files` silently caps at 100 entries. Use the paginated files API and concatenate the per-file `.patch` blobs — they preserve `@@ -old +new @@` headers and are equivalent to a unified diff for hunk parsing:

```bash
gh api --paginate "repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/files" \
  --jq '.[] | "=== FILE: \(.filename) (status=\(.status), +\(.additions)/-\(.deletions)) ===\n\(.patch // "(no patch — binary)")\n"' \
  > /tmp/pr-{PR_NUMBER}-diff.txt
```

For a **re-review**: also save the diff of only the new commits. Default: compare the SHA of the last commit before `{LAST_REVIEW.submitted_at}` to `{HEAD_SHA}`:

```bash
gh api repos/{OWNER}/{REPO}/compare/{LAST_REVIEWED_SHA}...{HEAD_SHA} \
  --jq '.files[] | "=== \(.filename) ===\n\(.patch // "(binary)")"' \
  > /tmp/pr-{PR_NUMBER}-new-diff.txt
```

**Fix-commit-window override**: when the author both pushed fix commits AND merged the base branch in (development → feature), the `LAST_REVIEWED_SHA…HEAD_SHA` window contains hundreds of unrelated files from the base merge. Use `compare(fix_commit_parent, fix_commit)` to isolate the author's intentional changes — that's the actual surface to verify against the prior findings. Pick this when `{NEW_COMMITS}` contains a clearly-named fix commit (e.g. `fix(security): …`) plus a separate `Merge branch 'development'` commit. Verified on openregister#1427: ~330 files of dev-branch noise vs the 11 files actually changed.

If a specific file's diff is needed during analysis, extract it:
```bash
sed -n '/^diff --git a\/{PATH}/,/^diff --git/p' /tmp/pr-{PR_NUMBER}-diff.txt | head -200
```

---

## Step 5a: Fetch PR Commit List

Skip if `{IS_REREVIEW}` is true (Step 3 already fetched the list). Otherwise:
`gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/commits --jq '[.[] | {sha:.sha, message:.commit.message, date:.commit.author.date}]'`
→ store as `{PR_COMMITS}` for use by Step 5b.

---

## Step 5b: Fetch Merged PR Context

Read [references/merged-pr-context.md](references/merged-pr-context.md) for the full fetch
protocol, context file format, and truncation rules.

Set `{HAS_MERGED_CONTEXT}=true` if any PRs were fetched, otherwise `false`.

---

## Step 5c: Run Mechanical Gates Against PR Diff

For each PR (sequential — shared clone), run the 13 hydra-gates against the PR's head SHA,
scoped to the diff. Findings are pre-flagged into the analysis brief as confirmed 🔴
blockers so the analysis agent (Step 6) can focus on semantic issues.

Follow the full protocol in [references/mechanical-gates.md](references/mechanical-gates.md):

- Command: `bash scripts/run-gates-on-pr.sh {OWNER} {REPO} {PR_NUMBER} {BASE_REF}`.
- Exit-code semantics (0 = all pass; 1–13 = N gates failed; 97/98/99 = setup error → skip).
- Storage shape: `{PR_MAP}[PR_NUMBER].gateFindings = [{n, name, reason, file_lines, fix_skill}]`
  plus `gateStatus = "passed" | "failed" | "skipped"`.
- **Protected gates** (5, 7, 8, 9, 10, 11, 12, 13) — failures force `REQUEST_CHANGES`
  in Step 9 regardless of mode.
- **Unprotected gates** (1, 2, 3, 4, 6) — surface as 🔴 findings but follow normal
  strictness rules in Step 9.

---

## Step 5d: Detect Content-Type Patterns and Stage Authoring Guides

Some file additions/modifications in the PR have canonical authoring guides in `ConductionNL/.github/docs/claude/`. For each pattern that matches the PR's file list, fetch the relevant guide into `/tmp/pr-{PR_NUMBER}-guides/` so the analysis subagent can compare the new content against the convention.

Detection runs against the PR's file list. **All triggers below fire on additions AND modifications** — authoring conventions apply equally to a freshly-added spec and to an edit that adds a new Requirement to an existing one. The migration checklist also applies to both, with the additional twist that a modification of an already-merged migration is itself flagged as 🟡 (see §7.1 in `content-type-guides.md`).

| Trigger pattern (file in PR) | Fetched guide(s) | Notes |
|---|---|---|
| OpenSpec specs (`openspec/**/spec.md`) | `writing-specs.md` + `conduction-spec-schema.yaml` | — |
| ADRs (`openspec/architecture/adr-*.md`) | `writing-adrs.md` | — |
| Skill (`.claude/skills/<name>/SKILL.md`) added or modified | `writing-skills.md` | **Hydra-only:** if `skill-level-overview.html` is not also in the PR, preflag a 🟡 finding (any SKILL.md change — including a description edit — requires re-running `update-skill-overview.sh`). Note: this check verifies file presence only — it does not validate that the HTML was actually regenerated by the script (a hand-edited HTML still satisfies the presence check). |
| Docs (`docs/**/*.md`, top-level `README.md`) | `writing-docs.md` | — |
| E2E tests (`tests/e2e/**/*.spec.{ts,js}`, `playwright.config.*`) | `playwright-setup.md` + `testing.md` | — |
| Vue components (`src/**/*.vue`) added or modified | `frontend-standards.md` | **Supplements** mechanical gates 10–13 + review-checklist Frontend section. Do NOT re-flag what gates already pre-flagged. |
| Migrations (`lib/Migration/Version*.php`) added or modified | (none — inline checklist) | Modifying a shipped migration is itself a 🟡 — the right fix is usually a follow-up migration. |
| CI/CD (`.github/workflows/*.yml`) | (none — inline checklist) | — |
| Dockerfiles | `docker.md` + inline checklist | — |
| Dependency manifests (`composer.json`, `package.json`) | (none — inline checklist) | Defer CVE checks to mechanical gate 4. |
| i18n (`l10n/*.js`, `translationfiles/`) | (none — inline checklist) | ADR-007. |
| App manifest (`appinfo/info.xml`) | (none — inline checklist) | CHANGELOG parity. |

Run the detection + fetch protocol from [references/content-type-guides.md](references/content-type-guides.md). Store outputs on `{PR_MAP}[PR_NUMBER]`:

- `stagedGuides`: `[{trigger, guideFiles}]`
- `preflaggedFindings`: `[{severity, title, file, body}]`
- `inlineChecklists`: `[{pattern, checklist}]`

Failures (network, 404) are logged and skipped — they don't block the review.

Step 6's brief embeds all three into the subagent prompt.

---

## Step 6: Deep Analysis

**For batch mode**: spawn one analysis subagent **per PR in parallel** (all in a single
Agent tool call), each with `model: "{AGENT_MODEL}"` (from Step 0). Each subagent is
independent and receives only its own PR's data. Wait for all subagents to complete before
moving to Step 7. Store each subagent's findings in `{PR_MAP}[PR_NUMBER].findings`.

**For single PR**: spawn one analysis subagent with `model: "{ORCHESTRATOR_MODEL}"` (inherits
the orchestrator model — no separate choice).

Run the analysis in an isolated context (a fresh general-purpose delegate).
Follow the full briefing and instruction template in [references/analysis-brief.md](references/analysis-brief.md).

---

## Step 7: Present Findings in Chat

Print a header — `### Analysis: #N — title  [Mode: ...]` for first review or `### Re-review: #N — title  [N new commits since ...]` for re-review — followed by 🔴 / 🟡 / 🟢 finding bullets. For batch mode: consolidated table first (PR | Title | Mode | CI | 🔴 | 🟡 | 🟢 | Verdict), then per-PR detail.

CI banner appears only when `{failingChecks}` is non-empty — bold "Required checks failing" if any are required, plain "CI failing" otherwise. Pre-discussed findings get a `_(discussed in merged PR #N)_` suffix but stay in the list.

See [references/finding-presentation.md](references/finding-presentation.md) for the full format templates.

Use AskUserQuestion to confirm before posting. Single PR: "Post these findings and submit a formal review?" (Yes / Skip some / Edit first). Batch: "Post findings for all N PRs?" (Yes / Select PRs / Edit first / Cancel).

---

## Step 7a: Offer Local Testing (Optional)

After presenting findings, optionally test the PR's changes against a local clone before
submitting the verdict. Skip the rest of this step entirely if the user declines.

For each PR in `{PR_LIST}` (or once-globally for batch mode):

1. **Detect testable layers** from `{PR_MAP}[PR_NUMBER].changedFiles` — backend
   (`.php`, `lib/`, `appinfo/`, `tests/`, …), frontend (`.vue`, `.ts`, `.js`, `.css`,
   `package.json`, `src/`, …), infra (`Dockerfile*`, workflows). If only docs/configs
   changed, skip Step 7a.

2. **Ask via AskUserQuestion**: "PR #{N} touches {detected layers}. Test the changes
   locally before submitting the verdict?" — Yes / No / Per-PR (batch only). On No, skip.

3. **Locate or clone the repo**. Apply the discovery + clone protocol in
   [references/local-testing.md](references/local-testing.md) (sections 1–2). On miss,
   ask the user whether to clone and where.

3a. **Confirm Docker readiness** per section 2a of the reference. If Docker is down, ask
   whether to start it or have the user start it. For Nextcloud apps, locate the NC dev
   clone by walking up parents and matching `git remote get-url origin` against
   `*/nextcloud-docker-dev(.git)?` (not folder name — the clone may have been renamed).
   If found, offer to start the NC stack with `docker compose up -d nextcloud proxy` in
   that directory.

4. **Map layers to test skills**. Use the routing table in
   [references/local-testing.md](references/local-testing.md) (section 3) — feed in
   `{TESTABLE_LAYERS}`, `{IS_SECURITY_SENSITIVE}` (Step 4a), and PR size signals. Mark
   each candidate as recommended or optional.

5. **Build a test plan** using the template in
   [references/local-testing.md](references/local-testing.md) (section 4): repo path,
   layers, fetch + checkout commands, dependency install, recommended `/test-*` skill
   invocations, and per-finding manual checks tied to Step 7's 🔴 list.

6. **Confirm via AskUserQuestion**: "Plan ready — proceed, edit, or cancel?" On Edit,
   take the user's revisions and re-present. On Cancel, fall through to Step 8.

7. **Execute the plan** step by step. Capture pass/fail/notes per step. Merge any new
   issues into `{PR_MAP}[PR_NUMBER].findings` (with `source: "local-test"`) so they
   become inline comments in Step 8. Add a one-line summary to the Step 9 verdict body.

## Step 8: Post Inline Comments

Skip if `{INLINE_COMMENT_COUNT}` is 0.
Follow the full posting protocol in [references/post-inline-comments.md](references/post-inline-comments.md).

---

## Step 8b: Resolve Prior Threads (re-reviews only)

**Always run on re-reviews — does NOT depend on `{INLINE_COMMENT_COUNT}`.** Skip on first reviews. ConductionNL rulesets enforce `required_review_thread_resolution: true`, so APPROVE without thread resolution leaves the PR un-mergeable. Verdict-body links don't auto-resolve threads; the GraphQL `resolveReviewThread` mutation is the only path.

Follow the protocol in [references/post-inline-comments.md](references/post-inline-comments.md) under **"Resolve prior threads (re-review)"**. On APPROVE, resolve every thread in `{RESOLVED}`. On REQUEST_CHANGES, resolve only `{RESOLVED}` (leave `{STILL_OPEN}` open — they ARE the blockers). Re-query `unresolved` count after the loop; if > 0, warn the user with the unresolved thread IDs and ask whether to still submit the verdict or abort — never silently continue.

---

## Step 9: Submit Formal Review

Determine event type based on `{STRICTNESS_MODE}`:

Read [references/strictness-modes.md](references/strictness-modes.md) for the verdict
rules per mode. In summary:

| Mode | Verdict |
|------|---------|
| Quick | APPROVE unless definite 🔴 blockers |
| Standard | REQUEST_CHANGES if 🔴; else APPROVE |
| Thorough | REQUEST_CHANGES if 🔴; else APPROVE |
| Strict | REQUEST_CHANGES if any 🔴 OR 🟡 |

**Verdict overrides** (force `REQUEST_CHANGES` regardless of mode): non-empty `{failingRequiredChecks}` (CI override) and any failure on the protected mechanical-gate list 5/7/8/9/10/11/12/13 (security + ADR-004 hard rules). Non-required CI failures stay informational. Unprotected gates (1/2/3/4/6) feed the strictness verdict as normal 🔴 findings. Full override copy + review-body wording in [references/strictness-modes.md](references/strictness-modes.md).

For re-review: also consider `{STILL_OPEN}` prior comments when determining verdict. If all prior blockers are resolved and no new ones exist, lean toward APPROVE (per mode).

**Submit + verdict body**: see the "Submitting the review" section in [references/strictness-modes.md](references/strictness-modes.md) for the `gh api` invocation, the `html_url` fetch needed to build clickable verdict-body links, and the one-or-two-sentence body templates for APPROVE / REQUEST_CHANGES (with the re-review variant that summarises resolved / remaining / new findings).

---

## Capture Learnings

After completion, append new observations to [learnings.md](learnings.md):

- **Patterns That Work** — approaches that caught real issues or resolved threads cleanly
- **Mistakes to Avoid** — errors in analysis, comment placement, or verdict
- **Domain Knowledge** — facts about the codebase or patterns discovered
- **Open Questions** — unresolved items for future sessions

Format: `- YYYY-MM-DD: <insight>`. One insight per bullet. Skip if nothing new.

---

## Guardrails

- Never post comments on lines outside the diff. Verify line numbers against hunk ranges.
- Never submit both COMMENT and REQUEST_CHANGES/APPROVE in a single API call — inline comments go in a COMMENT review; verdict is a separate call.
- Never skip the isolated analysis step for PRs with more than 5 changed files.
- Never fabricate findings. If nothing is wrong, APPROVE with an empty comment list.
- Do not repost prior comments — check `{PRIOR_COMMENTS}` first; if a finding duplicates an unresolved prior comment, reply to that thread instead of opening a new one.
- Do not post to PRs on repos outside the user's control without explicit confirmation.
- **One comment per finding** — never combine concerns. Authors can only resolve individual GitHub review comments, not paragraphs.
- **Verify group completeness** — when patching a set of similar methods or fixing a bug class, check every other occurrence in the diff. The same PR that fixes one mapper can silently repeat the bug in a migration file. Ask: "where else in this PR's diff does the same pattern appear?"
- **Always verify deferral and author claims** — when an author defers a concern as "pre-existing" or "out of scope", grep the diff to confirm the change site is not in the PR. When a subagent escalates a finding based on inferred semantics (e.g. GitHub Actions `if:` logic), trace the expression manually before accepting it as a blocker.
- **Quick mode**: when uncertain, post as 🟡 Concern, not 🔴 Blocker; in doubt, approve.
- **Strict mode**: when uncertain, post as 🔴 Blocker; request changes for any 🟡 Concern.

The Consolidated Principles in [learnings.md](learnings.md) cover deferral verification, group completeness across the diff, the three-step thread resolution protocol, the reply-endpoint URL form, mega-PR diff fetching, and other cross-PR rules. Re-read them before each session.
