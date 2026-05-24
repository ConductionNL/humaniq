---
name: report-out
description: Daily end-of-day report — scans local repos and the user's GitHub activity today, suggests committing/pushing uncommitted changes, creating PRs for orphan branches, and lifecycle actions on tracking issues (close, follow-up); ends with a copy-paste Dutch Slack report-out message
metadata:
  category: Workflow
  tags: [daily, git, github, report, dutch, end-of-day]
---

# Report Out — Daily End-of-Day Workflow

Scans the user's local git repositories for today's commits and uncommitted changes, auto-discovers GitHub issues and PRs the user has interacted with, surfaces suggestions for actions that may be needed (new PRs, issue status changes, follow-up issues), then walks the user through optional updates. **Always ends with a single copy-paste-ready Dutch report-out message** referencing the issues/PRs the user has created or merged.

**Input**: invoked as `/report-out`. Optional argument: a date string (`YYYY-MM-DD`) — defaults to today.

---

## Step 0: Detect Date and User Identity

Resolve the target date and the user's git/GitHub identity dynamically — never hardcode.

```bash
TARGET_DATE="${ARGUMENTS:-$(date +%Y-%m-%d)}"
TZ_OFFSET=$(date +%z)                            # e.g. +0200 (DST-aware)
SINCE="${TARGET_DATE}T00:00:00${TZ_OFFSET}"
UNTIL="${TARGET_DATE}T23:59:59${TZ_OFFSET}"
DISPLAY_DATE=$(date -d "$TARGET_DATE" +%d-%m-%Y) # Dutch DD-MM-YYYY

GIT_AUTHOR=$(git config --global user.name 2>/dev/null || git config user.name)
GIT_EMAIL=$(git config --global user.email 2>/dev/null || git config user.email)
GH_LOGIN=$(gh api user --jq '.login' 2>/dev/null)
HOME_DIR="${HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"
```

Store `{TARGET_DATE}`, `{SINCE}`, `{UNTIL}`, `{DISPLAY_DATE}`, `{GIT_AUTHOR}`, `{GH_LOGIN}`, `{HOME_DIR}`.

If `{GH_LOGIN}` is empty, prompt the user to run `gh auth login` and stop. If `{GIT_AUTHOR}` is empty, prompt the user to set `git config --global user.name "..."` and stop.

---

## Step 1: Ask for Additional Input (Start)

Ask using AskUserQuestion: **"Anything specific to take into account for today's report-out?"**
- **Nothing extra — proceed with defaults**
- **Yes — let me add context** → capture as `{USER_CONTEXT}`
- **Skip the issue/PR updates — just the report-out** → set `{REPORT_ONLY}=true`

---

## Step 2: Confirm Scan Scope

The Hydra repo MUST always be included — it's the user's primary work repo.

Ask using AskUserQuestion: **"What scope should I scan for `{DISPLAY_DATE}`?"**
- **Default** — exclude `wordpress-docker`, `claude-code-config`, and `spotify-playlist-manager` only when present
- **Default + extra excludes** — also exclude `nextcloud-docker-dev` (root only, NOT its `apps-extra/*` children), `openconnector`, `planix`
- **Custom** — let me specify

Store as `{EXCLUDED_REPOS}`. Hydra is **never** self-excluded.

---

## Step 3: Discover Local Git Repositories

Run the discovery routine in [references/git-discovery.md](references/git-discovery.md). Store as `{REPO_LIST}`.

---

## Step 4: Scan Commits and Uncommitted Changes

For each repo in `{REPO_LIST}`, collect:

```bash
git -C "$repo" log --since="{SINCE}" --until="{UNTIL}" --author="{GIT_AUTHOR}" --pretty=format:"%h|%s|%H"
git -C "$repo" status --short
git -C "$repo" branch --show-current
git -C "$repo" remote get-url origin
```

If `--author="{GIT_AUTHOR}"` returns 0 commits in a repo with same-day commits, fall back to `--author="{GIT_EMAIL}"`. Never broaden to "any author".

Store as `{REPOS_WITH_ACTIVITY}` (only repos with commits today or uncommitted changes).

---

## Step 5: Discover GitHub Interactions Today

Per [references/interaction-discovery.md](references/interaction-discovery.md), run the `gh search prs/issues --author/--commenter/--merged "@me" --updated ">={TARGET_DATE}"` queries and deduplicate by `(repository.nameWithOwner, number)`. Split into `{CREATED_OR_MERGED_PRS}`, `{COMMENTED_ISSUES}`, `{TOUCHED_PRS}`.

⚠️ **Per-PR validation is mandatory** before user-visible output. `gh search prs --merged ">=$DATE"` and `--updated ">=$DATE"` indexes lag — validate `mergedAt` per-PR via `gh pr view N --json mergedAt` and drop candidates whose date ≠ `{TARGET_DATE}` in the user's TZ.

---

## Step 5.5: Detect Branches Without an Open PR

Per [references/branch-pr-detection.md](references/branch-pr-detection.md), for each entry in `{REPOS_WITH_ACTIVITY}`:
- Skip default/integration branches (`main`, `master`, `development`, `beta`, `staging`).
- Skip branches whose upstream is `[gone]` (leftover state, not in-progress work).
- Check `gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state open` — if zero, flag as orphan.
- Skip a flagged branch if all today's commits were already part of a PR that was closed/merged today (don't suggest re-creation).

Store as `{BRANCHES_WITHOUT_PR}` with commit count today, latest commit subject, and ahead/behind vs the likely target branch.

---

## Step 5.6: Detect Issue Lifecycle Hints

Per [references/issue-lifecycle.md](references/issue-lifecycle.md), tag each open issue in `{COMMENTED_ISSUES}` (plus any saved tracking-issue mappings) with one of: `close-suggested`, `closing-trailer-detected`, `stale-and-busy`, `needs-followup`. Store as `{ISSUE_SUGGESTIONS}`. Do NOT take action yet.

---

## Step 5.7: Discover PR Reviews Submitted Today

Per the "Reviews submitted today (authoritative)" section in [references/interaction-discovery.md](references/interaction-discovery.md), use the per-PR `/reviews` endpoint — the events feed misses reviews and reports wrong verdicts.

Aggregate into `{REVIEWED_PRS_TODAY}` as `(owner_repo, pr_number, pr_title, strongest_state)`. Strongest state per PR per day: `APPROVED > CHANGES_REQUESTED > COMMENTED > inline-only`. Drop PRs whose filtered `/reviews` is empty.

⚠️ Do NOT include verdict count tallies in user-facing output. Outcomes only. Surfaces in two places:
1. Step 6 overview — the 🔎 Reviews submitted today section.
2. Step 10 final message — one consolidated line: `N PR reviews uitgevoerd verspreid over <repos>`.

---

## Step 6: Show Overview + Ask Direction

Render the overview using the canonical structure in [templates/overview.md](templates/overview.md). The seven sections (🖥️ Local repos / 🟢 PRs merged / 🟡 Open PRs / 💬 Issues touched / 🔎 Reviews / 📝 Uncommitted / 🆕 Branches without PR), their order, headers, and per-section formatting are fixed.

If `{REPORT_ONLY}` is true, skip ahead to Step 10.

Otherwise ask using AskUserQuestion: **"What would you like to handle?"**
- **Tracking issues + status suggestions** — Step 7
- **PR updates + creation suggestions** — Step 8 → Step 8.4 → Step 8.5
- **All of the above, then finalize** — Step 7 → Step 8 → Step 8.4 → Step 8.5 → Step 9 → Step 10
- **Skip directly to final report-out** — Step 9 → Step 10

---

## Step 7: Update Tracking Issues + Lifecycle Suggestions

### 7a. Check existing comments from the last 24 hours

```bash
SINCE_24H=$(date -u -d '24 hours ago' +%Y-%m-%dT%H:%M:%SZ)
gh api repos/{OWNER}/{REPO}/issues/{N}/comments \
  --jq '[.[] | select(.user.login == "{GH_LOGIN}" and .created_at > "'"$SINCE_24H"'") | {id, created_at, body_preview: (.body[0:200])}]'
```

If a recent user comment exists, ask Edit / New / Skip per [references/comment-update-protocol.md](references/comment-update-protocol.md).

### 7b. Draft and post the comment

Use [templates/issue-comment.md](templates/issue-comment.md). Show draft → ask Yes/Edit/Skip.

### 7c. Issue status suggestions

If the issue has a `close-suggested` or `closing-trailer-detected` tag, ask using AskUserQuestion: **"Issue #{N} `{title}` — {reason}. Close it?"**
- **Yes, close as completed** — `gh api repos/{OWNER}/{REPO}/issues/{N} -X PATCH -f state=closed -f state_reason=completed`
- **Yes, close as not-planned** — `state_reason=not_planned`
- **Add a label instead** → ask which, then `POST` to `/labels`
- **Skip** — leave open

Show the proposed action in chat before executing.

### 7d. Follow-up issue suggestions

If the issue has a `stale-and-busy` or `needs-followup` tag, ask using AskUserQuestion: **"Issue #{N} is {age} days old with {N_comments} comments. Create a follow-up issue?"**
- **Yes, draft a follow-up** — per [references/issue-lifecycle.md](references/issue-lifecycle.md). Body must reference parent (`Volgt op #{N}`), summarize done-so-far (1–3 bullets), list what's open, and incorporate `{USER_CONTEXT}`. Show, ask "Create?", then `POST` to `/issues`.
- **Skip — keep adding to the original**

### 7d.5. In-Progress + touched-today sweep

After per-issue suggestions, sweep the broader set per [references/in-progress-sweep.md](references/in-progress-sweep.md): union of org Kanban `In Progress` items + today's `--commenter`/`--author` results. For each `(repo, N)`: skip if already commented today, skip with a chat note if today's work doesn't touch its scope, otherwise draft → show → Yes/Edit/Skip → post.

### 7e. Today's insights → standalone new issue

Once per run, ask: **"Did anything come up today that warrants its own new tracking issue (not a comment on an existing one)?"**
- **No, all in existing issues**
- **Yes — let me describe it** → ask for repo, working title, 2–4 bullets. Draft Dutch issue body. Show. Ask "Create?". If yes, `POST` to `/issues`.

---

## Step 8: Update PR Titles and Descriptions

For each open PR in `{CREATED_OR_MERGED_PRS}` with new commits today, follow [references/pr-update-protocol.md](references/pr-update-protocol.md). Prefer extending bullets over adding new ones. Show diff → ask Yes/Edit/Skip → patch via `gh api repos/{OWNER}/{REPO}/pulls/{N} -X PATCH`. Never use `gh pr edit`.

---

## Step 8.4: Handle Uncommitted Changes

For each repo in `{REPOS_WITH_ACTIVITY}` with uncommitted changes, show a summary (file list with `M`/`A`/`??` status) and ask:

**"Uncommitted changes in `{repo}` on `{branch}`. What would you like to do?"**
- **Draft a commit message and commit** — propose a Conventional Commits prefix (`feat:` / `fix:` / `docs:` / `chore:`) from the file types, draft a 1–2 sentence subject, show, ask "Looks good?", then commit
- **I'll provide the message** — ask for full subject + optional body, then commit
- **Stash with WIP label** — `git stash push -m "WIP from /report-out {TARGET_DATE}"` (reversible)
- **Skip — leave as-is**

Commit execution (only after explicit Yes):

```bash
git -C "$repo" add -A
git -C "$repo" commit -m "$COMMIT_SUBJECT" -m "$OPTIONAL_BODY"
```

If pre-commit hooks fail, surface the failure and ask how to proceed (fix and retry / skip / `--no-verify` only with explicit user authorization).

After a successful commit, ask: **"Push `{branch}` to origin now?"** — see [references/push-protocol.md](references/push-protocol.md) for authorization and execution.

**What this step is NOT for:** repos with no commits today and no `{USER_CONTEXT}` mentions (may be in-progress WIP), and excluded repos.

---

## Step 8.5: Suggest Creating PRs for Orphan Branches

For each `(owner_repo, branch)` in `{BRANCHES_WITHOUT_PR}`, show the user the branch state and ask:

**"Create a PR for `{branch}`?"**
- **Yes — launch `/create-pr` for me** → tell the user to run `/create-pr` next (handles push authorization, target detection, CI checks, title/body). Stop the suggestion here.
- **Yes — quick PR via API (no checks)** → per [references/push-protocol.md](references/push-protocol.md), confirm push authorization, draft a minimal title + body, show, ask "Looks good?", then push and `gh api .../pulls -X POST`
- **Not yet** / **Skip** — no action

`/create-pr` is the recommended path; the direct API path is reserved for explicit "quick PR" requests.

---

## Step 9: Ask for Additional Input (End)

Ask using AskUserQuestion: **"Anything else to mention in the final Slack report-out message?"**
- **Nothing — generate the message as-is**
- **Yes — let me add a note** → capture as `{FINAL_NOTE}`

---

## Step 10: Produce Final Copy-Paste Dutch Report-Out

Generate the Dutch message using [templates/report-out-message.md](templates/report-out-message.md). The template owns all formatting rules (dash-free, full URLs in PR's/Issues lists, status-emoji legend, reviews summary line, optional `{FINAL_NOTE}`). Present in a single fenced markdown block — the output is the user's deliverable.

---

## Capture Learnings

After execution, append dated observations to [learnings.md](learnings.md):
- **Patterns That Work** — drafting approaches that landed first try
- **Mistakes to Avoid** — wrong identity detection, wrong heuristic thresholds, missed branches, follow-ups that should not have been suggested
- **Domain Knowledge** — issue conventions, repo defaults, observed lifecycle patterns
- **Open Questions** — heuristic tuning, edge cases

Each entry: `- YYYY-MM-DD — {one atomic insight}`. Skip if nothing new was learned.

---

## Guardrails

- **No destructive git** — `git push`, `git commit`, `git stash` only after explicit user authorization in a free-text message. Show what will be staged/committed/pushed in chat before running.
- **No silent posting/patching/creating/closing** — every comment, PR description patch, issue creation, and issue close requires `AskUserQuestion` confirmation with the draft shown in chat first.
- **Never use `gh pr edit`** — fails with "Projects (classic) is being deprecated". Use `gh api repos/{OWNER}/{REPO}/pulls/{N} -X PATCH` instead.
- **`gh search prs --json` does NOT expose `headRefName` / `baseRefName`** — those fields error out and cancel parallel siblings. Use `gh pr view N --json baseRefName,headRefName` per-PR for branch info.
- **Always check 24h comment history** before posting a new comment — offer Edit on the most recent if it exists.
- **Dutch language only** for issue comments, PR descriptions (when patching), and the final report-out.
- **Identity is dynamic** — `git config user.name`, `gh api user`, `$HOME`. No hardcoded paths or names. Author filter falls back to `{GIT_EMAIL}`, never to "any author".
- **Exclude-list scoping** — exclude only the ROOT of `nextcloud-docker-dev`, never its `apps-extra/*` children.
- **Default branches never get PR suggestions** — `main`, `master`, `development`, `beta`, `staging` are skipped in Step 5.5.
- **Uncommitted-change handling is opt-in per repo** — stash is the safe default for in-progress work.
- **Never bypass pre-commit hooks** — no `--no-verify` unless the user explicitly authorizes it in the current message.
- **No self-exclusion (Hydra variant)** — this skill always scans the Hydra repo. Only `wordpress-docker`, `claude-code-config`, and `spotify-playlist-manager` are excluded by default when present.
- **The 5-day / 10-comment thresholds for follow-up suggestions are heuristics**, not rules. If the user dismisses a suggestion, do not surface it again in the same run.
- **Memory check** — save feedback as a memory entry when the user corrects or confirms a non-obvious choice.
