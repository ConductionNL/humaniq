# Overview Template (Step 6 — in-chat overview)

The mid-skill overview shown to the user in chat before asking what to handle. **This is NOT the Slack message** — that template lives in [report-out-message.md](report-out-message.md). This overview is rendered as Markdown in the chat UI.

The structure was validated and approved by the user on 2026-05-21 as the standing format. Do not deviate from the section order, header text, or emoji set — those are fixed.

## Skeleton

```
Here's the overview for {DISPLAY_DATE}:

### 🖥️ Local repos with activity

| Repo | Branch | Commits | Uncommitted |
|---|---|---|---|
| {repo} | `{branch}` | {N} | {N file(s) ({path}) | clean} |

### 🟢 PRs merged today

- {owner}/{repo}#{N} — {title} ✅ ({HH:MM})

### 🟡 Open PRs (created/touched today)

- {owner}/{repo}#{N} — {title} {🆕 if createdAt is TARGET_DATE} {optional (qualifier such as Hydra-built)}

### 💬 Issues touched today

- {owner}/{repo}#{N} — {short status summarizing today's interaction}

### 🔎 Reviews submitted today ({N} PRs)

{repo}#N, #M · {repo}#N · {repo}#N

### 📝 Uncommitted

- {repo}: `{path}` ({N} file(s)) — {one-line note about content if known}

### 🆕 Branches without an open PR

- {repo} `{branch}` — {N} commits today, latest: {subject}
```

## Section-by-section rules

### 0. Heading line

- Exactly: `Here's the overview for {DISPLAY_DATE}:` where `{DISPLAY_DATE}` is DD-MM-YYYY.
- One blank line below before the first section.

### 1. 🖥️ Local repos with activity

- Render as a Markdown table with columns `Repo | Branch | Commits | Uncommitted`.
- One row per repo that had commits today OR uncommitted changes.
- `Branch` cell wraps the branch name in backticks.
- `Commits` is the integer count of today's commits authored by `{GIT_AUTHOR}`.
- `Uncommitted` shows `clean` when the working tree is clean, otherwise `N file(s) ({short path or filename})`.
- Omit the section entirely when no repos have activity.

### 2. 🟢 PRs merged today

- One bullet per PR where `mergedAt` falls on `{TARGET_DATE}` (per-PR validated via `gh pr view N --json mergedAt`).
- Format: `{owner}/{repo}#{N} — {title} ✅ ({HH:MM})`.
- The `({HH:MM})` is the merge time in the user's local TZ.
- Sort ascending by merge time.
- Omit the section entirely when none.

### 3. 🟡 Open PRs (created/touched today)

- One bullet per open PR that was created today, updated today, or has new commits today.
- Format: `{owner}/{repo}#{N} — {title} {flags}` where flags include:
  - `🆕` if `createdAt` is `{TARGET_DATE}`
  - Optional parenthetical qualifier such as `(Hydra-built)` when the PR was opened by a pipeline persona but authored as the user
- Sort newest-first (most-recent activity first).
- Omit the section entirely when none.

### 4. 💬 Issues touched today

- One bullet per issue the user authored or commented on today.
- Format: `{owner}/{repo}#{N} — {short status}` where `short status` summarizes today's interaction (e.g. "research/plan comment posted at 15:24", "linked PR #38", "noted PR merged, awaiting main+Pluvo placement", "authored today (OpenSpec retrofit tutorial)", "touched (no user comment in 24h)").
- Omit the section entirely when none.

### 5. 🔎 Reviews submitted today (N PRs)

- The `({N} PRs)` count in the header reflects the de-duplicated count from `{REVIEWED_PRS_TODAY}` (Step 5.7).
- Single rolled-up line below the header — no per-PR bullets.
- Separator rules:
  - PRs within the same repo: comma-separated, repo prefix on first one only — e.g. `openregister#1612, #1431`
  - Different repos: `·` (middle-dot) separator with a space on each side — e.g. `openregister#1612, #1431 · procest#486, #483, #482`
- Sort repos in descending order of PR count (most-reviewed repo first).
- Omit the section entirely when none.

### 6. 📝 Uncommitted

- One bullet per repo with uncommitted changes, format: `{repo}: \`{path}\` ({N} file(s)) — {one-line note}`.
- If multiple files in one repo, list the first path and `({N} files total)` instead of one per file.
- The note should describe what the change appears to be (e.g. "appears auto-generated", "WIP — in-progress feature", "tracked but unstaged whitespace").
- **Always render this section** — when no repos have uncommitted changes, render: `None — all repos clean`.

### 7. 🆕 Branches without an open PR

- One bullet per `(owner_repo, branch)` pair from `{BRANCHES_WITHOUT_PR}` (Step 5.5).
- Format: `{repo} \`{branch}\` — {N} commits today, latest: {subject}`.
- **Always render this section** — when no orphan branches exist, render: `None — {brief reason}` (e.g. "None — both active branches have open PRs").

## What this overview is NOT

- It is NOT the final Slack message. The Slack message lives in [report-out-message.md](report-out-message.md) and is dash-free.
- It is NOT verbose. Each bullet is one line — detail belongs in the linked PRs/issues, not in the overview.
- It is NOT a place for verdict breakdowns. Section 5 is a count + repo list only — no `(N CHANGES_REQUESTED, M APPROVED)` tallies.
