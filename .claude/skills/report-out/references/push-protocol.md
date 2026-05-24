# Push Protocol

Shared by Step 8.4 (post-commit push offer) and Step 8.5 (PR-creation push). Both paths funnel through these rules so the git-push hook stays honored.

## Authorization requirement

`~/.claude/hooks/block-write-commands.sh` only detects push-authorization phrases ("please git push", "push for me", etc.) in **free-text user messages**. It does NOT inspect:
- `AskUserQuestion` answers
- `<system-reminder>` "user sent a new message while you were working" interjections

So an in-skill confirmation is not enough — the hook needs a separate free-text message. Always surface the requirement up front:

> "Pushing `{branch}` requires the project's git-push authorization phrase in a free-text user message — phrases like 'please git push' or 'push for me'. AskUserQuestion answers do NOT count for the hook. Your current message must contain the phrase, or the push will be blocked."

## Performing the push

After the user confirms in chat:

```bash
git -C "$repo" push -u origin "$branch"
```

If the hook blocks the push, report:

> "Push blocked by the git-push hook. Send your next message including 'please git push' to authorize, then re-run this skill or run `git -C {repo} push -u origin {branch}` manually."

## What is forbidden

Never bypass the hook with `--no-verify`, `git -c hooks.autohooksenabled=false`, or any other escape — that defeats the user's safety policy.

## Step-8.5 PR creation after push

When the user picked "Quick PR via API" in Step 8.5, follow the push with:

```bash
gh api "repos/$OWNER_REPO/pulls" -X POST \
  -f title="$PR_TITLE" -f head="$branch" -f base="$TARGET_BASE" -f body="$PR_BODY" \
  --jq '.html_url'
```

For most cases, prefer delegating to `/create-pr` rather than this direct API path — it handles CI checks, target detection, and title/body drafting.
