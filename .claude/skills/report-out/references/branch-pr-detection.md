# Detecting Branches That Need a PR

For each repo with today's commits, determine whether the active branch already has an open PR. If not, surface it as a suggestion in Step 5.5.

## Detection logic

```bash
# For a single repo:
BRANCH=$(git -C "$repo" branch --show-current)
[ -z "$BRANCH" ] && return  # detached HEAD — skip

REMOTE_URL=$(git -C "$repo" remote get-url origin 2>/dev/null)
[ -z "$REMOTE_URL" ] && return  # no remote — skip
OWNER_REPO=$(echo "$REMOTE_URL" | sed -E 's|.*github\.com[:/]([^/]+/[^/.]+)(\.git)?$|\1|')

# Skip default/integration branches
case "$BRANCH" in
  main|master|development|beta|staging|production) return ;;
esac

# Has the branch been pushed?
PUSHED=$(git -C "$repo" rev-parse --verify --quiet "origin/$BRANCH")

# Open PR with this branch as head?
PR_COUNT=$(gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state open --json number 2>/dev/null \
  | jq length)
```

## Flagging criteria

A branch is added to `{BRANCHES_WITHOUT_PR}` when ALL of:

- Has at least one commit by `{GIT_AUTHOR}` on `{TARGET_DATE}`
- Branch name does NOT match the default-branch list above
- `gh pr list --head $BRANCH --state open` returns 0 results

## Skip branches whose upstream is `[gone]`

`git status -sb` showing `## branch...origin/branch [gone]` means the remote ref was deleted (force-pushed away, branch deleted via merge/cleanup). Do NOT flag these as orphan-PR candidates — the local branch is leftover state, not in-progress work needing a PR.

```bash
UPSTREAM_STATE=$(git -C "$repo" status -sb | head -1)
case "$UPSTREAM_STATE" in
  *"[gone]"*) return ;;  # remote ref deleted — branch is leftover, not orphan
esac
```

The exception: if the branch has commits today AND no closed/merged PR exists for the branch name in the last 30 days, the user may be reusing a deleted branch name for new work. Verify via `gh pr list --head $BRANCH --state all --search "closed:>=$(date -d '30 days ago' +%Y-%m-%d)"` before skipping.

## Don't suggest re-creation if a PR was just merged today

Before flagging, check whether a PR for this branch was merged today (the user may have just landed it, and the branch is hanging around in the local checkout):

```bash
RECENT_MERGED=$(gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state merged \
  --search "merged:>=$TARGET_DATE" --json number,mergedAt --limit 1)

if [ "$(echo "$RECENT_MERGED" | jq length)" -gt 0 ]; then
  return  # PR was merged today — branch is post-merge cleanup, no new PR needed
fi
```

## Computing context for the suggestion

When surfacing the suggestion to the user in Step 5.5 / 8.5, include:

| Field | How to compute |
|-------|----------------|
| Commits today on this branch | `git log --since="$SINCE" --until="$UNTIL" --author="$GIT_AUTHOR" --oneline "$BRANCH"` |
| Latest commit subject | `git log -1 --pretty='%s' "$BRANCH"` |
| Likely target branch | `feature/*`/`bugfix/*` → `development`; `hotfix/*` → `main`; otherwise `main` |
| Ahead of target | `git rev-list "$TARGET..$BRANCH" --count` |
| Behind target | `git rev-list "$BRANCH..$TARGET" --count` |
| Pushed status | `origin/$BRANCH` exists vs. not |

## Conventional Commits prefix detection

When drafting the PR title in the "quick PR" path (Step 8.5), check whether the latest commit subjects already follow Conventional Commits:

```bash
LATEST=$(git -C "$repo" log -1 --pretty='%s' "$BRANCH")
case "$LATEST" in
  feat:*|feat\(*\):*|fix:*|fix\(*\):*|docs:*|chore:*|refactor:*|test:*|perf:*|ci:*|style:*)
    PR_TITLE="$LATEST"  # already conventional, use as-is
    ;;
  *)
    # Infer from branch name prefix
    case "$BRANCH" in
      feature/*) PR_TITLE="feat: $LATEST" ;;
      bugfix/*|fix/*) PR_TITLE="fix: $LATEST" ;;
      hotfix/*) PR_TITLE="fix: $LATEST" ;;
      docs/*) PR_TITLE="docs: $LATEST" ;;
      *) PR_TITLE="chore: $LATEST" ;;
    esac
    ;;
esac
```

For the body, list commits since the target branch divergence:

```bash
git -C "$repo" log "$TARGET..$BRANCH" --pretty='- %s' --no-merges
```

## PR title length: `gh pr create` truncates at ~70 characters

`gh pr create --title "..."` silently truncates titles longer than ~70 chars by inserting a Unicode ellipsis (`…`) and pushing the remainder into the body (the body then starts with `…rest`). When using the quick-PR path (`gh api .../pulls -X POST`), this does NOT happen — the API accepts the full title. But after creation, always read back the resulting title/body and offer to PATCH them clean if any UI tool involved in the chain truncated them. Titles up to ~76 chars are typically acceptable to PATCH back to.

## Workflow: uncommitted work doesn't match current branch's PR scope

When the user has uncommitted changes that don't belong on the currently checked-out branch (e.g. learnings file edited while on a merged feature branch), the cleanest path is:

```bash
git -C "$repo" stash push -m "moving to fresh branch" -- "$FILE"
git -C "$repo" checkout "$TARGET_BASE"     # development or main
git -C "$repo" pull --ff-only origin "$TARGET_BASE"
git -C "$repo" checkout -b "$NEW_BRANCH"
git -C "$repo" stash pop
# resolve any conflicts, then commit + push
```

Recommend this over "commit on the wrong-scoped branch" whenever the scope mismatch is non-trivial. Watch for merge conflicts when `stash pop` lands on a moved base — resolve preserving both sides, never discard one silently.

## When to delegate to `/create-pr` instead of using gh api directly

The skill offers two paths:

1. **Recommended**: tell the user to run `/create-pr` next. That skill handles CI detection, branch protection, lock-file checks, push authorization, target validation, etc.
2. **Quick path**: bare `gh api` POST. Use only when the user explicitly opts in. Skip CI checks, target validation, etc. Faster but less safe.

Default to the recommended path. Only use the quick path when the user has explicitly said "yes, quick PR via API" — don't make this the default offering.

## Anti-patterns

| Anti-pattern | Why wrong |
|--------------|-----------|
| Suggesting a PR for a default branch | `main`/`master`/`development` shouldn't have PRs targeted at themselves |
| Pushing the branch silently before asking | Violates the project's git-push policy; needs explicit authorization phrase |
| Reusing a closed PR's title verbatim | The branch may have new commits since the close — draft fresh from current commit log |
| Suggesting a PR for a branch with 0 unpushed AND 0 ahead-of-remote commits | Nothing new to PR; the user is just sitting on the branch |
