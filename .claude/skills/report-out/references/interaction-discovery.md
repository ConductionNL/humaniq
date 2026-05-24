# GitHub Interaction Discovery

Find issues and PRs the user has interacted with on the target date. Used in Step 5 to build a baseline list of items that should appear in the report-out and may need updating.

## Authoritative source: `gh search`

GitHub's search API is the canonical source. The `gh` CLI exposes it with built-in date filtering and `@me` self-reference.

### Queries

```bash
TARGET_DATE="${TARGET_DATE:-$(date +%Y-%m-%d)}"
SINCE_PARAM=">=$TARGET_DATE"
GH_LOGIN=$(gh api user --jq '.login')
```

#### PRs the user authored, updated today

```bash
gh search prs --author "@me" --updated "$SINCE_PARAM" --limit 30 \
  --json number,title,state,url,repository,updatedAt,createdAt,isDraft
```

#### PRs the user merged today (covers PRs they did not author but landed)

```bash
# Merged-by is not a search filter, so we list user-authored PRs filtered by mergedAt
gh search prs --author "@me" --merged "$SINCE_PARAM" --limit 30 \
  --json number,title,url,repository,mergedAt
```

#### Issues the user authored or commented on today

```bash
gh search issues --author "@me" --updated "$SINCE_PARAM" --limit 30 \
  --json number,title,state,url,repository,updatedAt

gh search issues --commenter "@me" --updated "$SINCE_PARAM" --limit 30 \
  --json number,title,state,url,repository,updatedAt
```

#### Reviews submitted today (authoritative — per-PR /reviews endpoint)

`gh api users/<me>/events` is NOT authoritative for this — it misses entire reviews and reports wrong verdicts. Always go through the per-PR reviews endpoint.

```bash
# 1) Candidate list (lossy — still filter per-PR below)
gh search prs --reviewed-by "@me" --updated "$SINCE_PARAM" --limit 50 \
  --json number,title,url,state,repository,updatedAt

# 2) For each candidate, fetch reviews submitted today
for ref in $candidates; do
  repo=$(echo "$ref" | sed 's/#.*//'); num=$(echo "$ref" | sed 's/.*#//')
  gh api "repos/$repo/pulls/$num/reviews" \
    --jq "[.[] | select(.user.login == \"$GH_LOGIN\" and (.submitted_at | startswith(\"$TARGET_DATE\"))) | {state, submitted_at}]"
done
```

Aggregate into `{REVIEWED_PRS_TODAY}` as `(owner_repo, pr_number, pr_title, strongest_state)` tuples. Verdict ranking per PR per day: `APPROVED > CHANGES_REQUESTED > COMMENTED > inline-only`. Drop PRs whose filtered `/reviews` is empty (candidate list is lossy).

Do NOT surface verdict counts in user-facing output. The Slack message uses one consolidated line: `{N} PR reviews uitgevoerd verspreid over <unique repo names>`.

## Deduplication

The above queries overlap. Deduplicate by `(repository.nameWithOwner, number)`:

```bash
jq -s 'add | unique_by(.repository.nameWithOwner + "#" + (.number|tostring))'
```

## Classification

Split the deduplicated list into:

| Bucket | Definition | Used for |
|--------|------------|----------|
| `created_today` | PRs/issues where `createdAt` falls on `{TARGET_DATE}` | "Created today" line in report-out |
| `merged_today` | PRs where `mergedAt` falls on `{TARGET_DATE}` | "Merged ✅" line in report-out |
| `commented_today` | Issues where the user commented today (not the author) | Tracking-issue candidates |
| `updated_today` | Anything else with `updatedAt` on the target date | Context for PR description updates |

## Fallback: user events feed

If `gh search` returns rate-limited or empty results (e.g. the search index has lag), fall back to the user events feed:

```bash
gh api "/users/$GH_LOGIN/events?per_page=100" \
  --jq '[.[] | select(.created_at | startswith("'"$TARGET_DATE"'")) | {type, repo: .repo.name, payload}]'
```

Event types relevant to this skill:

| Event type | Meaning |
|-----------|---------|
| `PullRequestEvent` (action: opened) | User created a PR |
| `PullRequestEvent` (action: closed, merged: true) | A PR the user authored was merged |
| `IssueCommentEvent` | User commented on an issue or PR |
| `IssuesEvent` (action: opened) | User opened an issue |

## Caveats

- **`gh search prs --merged` covers user-authored PRs only.** A PR authored by someone else but merged by the user does not appear. Use the events feed if "merged by me" coverage matters.
- **The events feed has a ~90-day rolling window.** For older `{TARGET_DATE}` values, only `gh search` works.
- **Search lag can be hours, not minutes.** `gh search prs --author "@me" --created/--updated ">=DATE"` has been observed to return empty results for a PR created 2+ hours earlier. Treat search as a starter, not the source of truth. For each repo in `{REPOS_WITH_ACTIVITY}` always cross-check with `gh pr list --repo "$OWNER_REPO" --head "$BRANCH" --state all --json number,title,state,url,createdAt,mergedAt` — that returns the PR immediately. The `--merged ">=DATE"` and `--updated ">=DATE"` indexes are even laggier; per-PR validation via `gh pr view N --json mergedAt` is mandatory before claiming a PR was "merged today" in the Slack message.

## Privacy

Only query for `@me`. Never enumerate other users' activity, even when the workflow involves a co-worker (e.g. a reviewer's username from the closing-comment trailer). The user can supply that name explicitly via `{USER_CONTEXT}` if it needs to appear in a comment.
