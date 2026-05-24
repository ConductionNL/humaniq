# Learnings — report-out

Dated, atomic observations from executions of this skill. One insight per bullet.

The **Capture Learnings** step in `SKILL.md` appends here directly for high-confidence observations. Consolidation trigger: review and consolidate when this file exceeds ~80–100 entries — merge duplicates, remove outdated items, promote validated principles to SKILL.md / references / templates.

## Patterns That Work

- 2026-05-21 — When `git status` flags a file as `M` at scan time but `git diff` returns empty later in the same run, the working tree is genuinely clean — likely a stat refresh from intermediate tooling. Re-running `git status` before Step 8.4 prevents a false uncommitted-changes prompt.
- 2026-05-21 — User explicitly requested that "🔎 Reviews submitted today (N PRs)" become a standard section of the Step 6 overview AND that the final Slack message include a `N PR reviews uitgevoerd verspreid over <repos>` line whenever reviews exist. Drives Step 5.7 + template change.
- 2026-05-21 — User approved the seven-section overview structure (🖥️ / 🟢 / 🟡 / 💬 / 🔎 / 📝 / 🆕) with specific per-section formatting (table for local repos, single rolled-up line for reviews using `·` repo-separator + `,` same-repo separator, "None — reason" placeholder for the two always-rendered tail sections). Codified in [templates/overview.md](templates/overview.md).

## Mistakes to Avoid

- 2026-05-21 — Don't prefix summary lines, PR's: entries, or Issues: entries with `- `. Slack renders the dash literally — it's not a bullet — and the user has to delete every dash before adding their own native Slack bullet formatting. Confirmed by user feedback on 2026-05-21.

## Domain Knowledge

_(Promoted entries live in SKILL.md / references / templates and have been removed.)_

## Open Questions

- 2026-05-04 — Should the skill auto-write a saved tracking-issue mapping at `$HOME/.claude/report-out/tracking-issues.json` after the user manually selects issues, or always require explicit confirmation? Decide after first 3 real runs.
- 2026-05-04 — When the user has multiple recent comments (within 24h) on the same issue, should we always offer the most recent one to edit, or list them all? Most recent seems right but unverified.
- 2026-05-04 — Should the final report-out also reference the tracking-issue COMMENT URLs (deep links) or just the issue URLs? Deep links are better but uglier in Slack.
- 2026-05-18 — Should the In-Progress sweep (Step 7d.5) cache the project/field/option IDs per machine in `$HOME/.claude/report-out/projects.json` to avoid the per-run GraphQL fetch, or query each run? Caching is faster; querying each run avoids stale IDs after a board rename.

## Consolidated Principles

Validated after 3+ confirmations or after resolving a measured eval failure. Promoted to SKILL.md / references / templates.

- **Hard-coded full URLs in Slack lists; shorthand allowed only in bullets that mirror list entries.** → [templates/report-out-message.md](templates/report-out-message.md) Link rules.
- **Status emoji legend (✅ 🟡 🔵 🔴 📝 🆕).** → [templates/report-out-message.md](templates/report-out-message.md) Status emoji legend.
- **`gh search` lag can be hours; always cross-check via `gh pr list --head BRANCH --state all` per repo, and validate `mergedAt` per-PR via `gh pr view`.** → [references/interaction-discovery.md](references/interaction-discovery.md) Caveats.
- **Skip branches with `[gone]` upstream from orphan-PR detection; they are leftover state, not in-progress work.** → [references/branch-pr-detection.md](references/branch-pr-detection.md) Skip branches whose upstream is [gone].
- **Don't suggest a PR for a branch whose only commits today were merged today (post-merge cleanup).** → [references/branch-pr-detection.md](references/branch-pr-detection.md) Don't suggest re-creation if a PR was just merged today.
- **Tracking issues typically live in the org-level `.github` repo; successor issues replace closed-out trackers (closing-trailer pattern).** → [references/tracking-issues.md](references/tracking-issues.md) Lifecycle.
- **Closing-trailer Dutch pattern: `Deze issue lijkt inhoudelijk klaar ...`.** → [references/issue-lifecycle.md](references/issue-lifecycle.md) Detecting the closing-trailer.
- **`gh pr create` truncates titles >~70 chars by inserting `…`; quick-PR API path does not — read back the result and PATCH if needed.** → [references/branch-pr-detection.md](references/branch-pr-detection.md) PR title length.
- **Stash → checkout base → pull → new branch → pop is the canonical flow when uncommitted work doesn't match the current branch's PR scope.** → [references/branch-pr-detection.md](references/branch-pr-detection.md) Workflow: uncommitted work doesn't match current branch's PR scope.
- **Same user uses varying status-comment header conventions across tracking issues; always read at least one prior comment for the local style.** → [references/comment-update-protocol.md](references/comment-update-protocol.md) Drafting an updated body.
- **Git-push hook only detects auth phrases in free-text user messages — not AskUserQuestion answers or system-reminder interjections.** → [SKILL.md](SKILL.md) Step 8.4 Push protocol.
- **Sweep In-Progress + touched-today issues for status updates each run.** → [references/in-progress-sweep.md](references/in-progress-sweep.md), [SKILL.md](SKILL.md) Step 7d.5.
- **No leading `- ` dashes anywhere in the final Slack message — user adds bullet formatting in Slack.** → [templates/report-out-message.md](templates/report-out-message.md) Heading rules + Rules for filling.
- **Reviews submitted today are a first-class section.** Step 5.7 discovers them via per-PR `/reviews` endpoint (events feed is not authoritative); Step 6 overview shows a 🔎 Reviews submitted today section; final Slack message includes a single rolled-up `N PR reviews uitgevoerd verspreid over <repos>` summary line — no verdict tallies. → [SKILL.md](SKILL.md) Step 5.7, [references/interaction-discovery.md](references/interaction-discovery.md) Reviews submitted today, [templates/report-out-message.md](templates/report-out-message.md) Reviews summary line.
- **Step 6 overview structure is canonical: 7 sections (🖥️ / 🟢 / 🟡 / 💬 / 🔎 / 📝 / 🆕) in fixed order, each with prescribed formatting.** → [templates/overview.md](templates/overview.md), [SKILL.md](SKILL.md) Step 6.
