**For batch mode**: iterate over each selected PR in sequence. Run the full Step 8 + Step 9
loop for PR #1, then PR #2, etc. Do not post to all PRs simultaneously — sequential posting
makes it easy to catch a rejected payload (e.g. 422 on a bad line number) and handle it
before moving on.

**New findings**: post as a single COMMENT review payload (same as before):

```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/reviews \
  --input /tmp/pr-{PR_NUMBER}-review.json \
  --jq '{id: .id, state: .state}'
```

JSON payload format per finding — see [comment-format.md](comment-format.md)
for body format. Use `{STRICTNESS_MODE}` to shape body wording:
- **Quick/Standard**: concise body, one paragraph max
- **Thorough/Strict**: full body with impact + suggested fix

**For pre-discussed findings** (`already_discussed_in` is non-empty): append a
blockquote at the end of the comment body before posting:

```markdown
> ⚠️ This concern was raised in merged PR #{N}: "{matched_text_excerpt}"
```

One line per matched PR if multiple. This surfaces the prior context directly in the
inline comment so the author sees it without needing to look up the history.

**Line number rules:**
- Only comment on lines in the diff (context or added lines)
- Verify: `grep -n "^@@" /tmp/pr-{PR_NUMBER}-diff.txt`
- If a target line is not in the diff, move to nearest hunk context line with a note

**For re-review — reply to resolved threads:**

The reply endpoint takes the **PR number**, not just the comment id. The comment-fetch endpoint is `/pulls/comments/{id}` (no PR number) which is the source of confusion — using that form here returns 404.

```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/comments/{PRIOR_COMMENT_ID}/replies \
  -X POST \
  -f body="✅ Resolved in {COMMIT_SHA[:7]}: {one-sentence explanation}"
```

Do this for each comment in `{RESOLVED}`. Do NOT reply to `{STILL_OPEN}` ones.

**Reply alone does NOT resolve the thread, and does NOT flip the PR verdict.** Reply state and review state and thread-resolution state are three independent things on GitHub. After replying you must (a) submit a fresh APPROVE / REQUEST_CHANGES review (Step 9) — this is what flips `reviewDecision` from `CHANGES_REQUESTED`; AND (b) call the `resolveReviewThread` GraphQL mutation (script below) — this is what marks `isResolved: true` on the PR sidebar. Skipping either leaves the PR looking like the findings are still outstanding.

Verify comments landed and capture html_urls for use in the Step 9 verdict body:
```bash
gh api repos/{OWNER}/{REPO}/pulls/{PR_NUMBER}/comments \
  --jq '[.[] | {id:.id, path:.path, line:.line, html_url:.html_url, body:.body[:60]}]'
```

Store the `html_url` of each new finding comment as `{COMMENT_URLS}` (keyed by finding title or ID). Pass these into Step 9 to build the linked verdict body.

## Resolve prior threads (re-review)

**This protocol runs on every re-review regardless of `{INLINE_COMMENT_COUNT}`.** ConductionNL rulesets enforce `required_review_thread_resolution: true` — an APPROVE verdict alone does NOT unblock merge if any thread is still `isResolved: false`. The verdict-body markdown links don't auto-resolve threads either. The GraphQL `resolveReviewThread` mutation is the only path.

**Three independent state fields**: reply state, review state (`reviewDecision`), and thread state (`isResolved`) are each settled by a different API call. Submitting APPROVE flips only `reviewDecision`. Posting a "Resolved in <sha>" reply flips nothing automatically. The GraphQL mutation is what flips `isResolved`.

### Step 1 — list unresolved threads

```bash
gh api graphql -f query='
query($owner:String!, $repo:String!, $pr:Int!) {
  repository(owner:$owner, name:$repo) {
    pullRequest(number:$pr) {
      reviewThreads(first:100) {
        nodes { id isResolved isOutdated path }
      }
    }
  }
}' -f owner={OWNER} -f repo={REPO} -F pr={PR_NUMBER} \
  --jq '.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved==false) | .id'
```

### Step 2 — resolve threads per verdict

- **APPROVE verdict** (all prior findings addressed): resolve every thread in `{RESOLVED}`. Informational / process threads where the underlying concern is no longer live also resolve. Step 9 references `{RESOLVED}` in the verdict body for traceability.
- **REQUEST_CHANGES verdict** (some prior findings still open): resolve only the threads in `{RESOLVED}`. Leave `{STILL_OPEN}` threads unresolved — they ARE the blockers.

For a small set (≤20), inline the mutation in a bash loop:
```bash
for tid in <ids>; do
  gh api graphql -f query='mutation($id:ID!){ resolveReviewThread(input:{threadId:$id}){ thread{ isResolved } } }' -f id="$tid" --jq '.data.resolveReviewThread.thread.isResolved'
done
```

For a larger set, use the template at [resolve-threads.py](../scripts/resolve-threads.py) — write to `/tmp/resolve_threads.py` and `python3` it. The Python form avoids shell-escaping issues with GraphQL mutation strings when node IDs contain special characters.

### Step 3 — verify

```bash
gh api graphql -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){reviewThreads(first:100){nodes{id isResolved}}}}}' \
  -f o={OWNER} -f r={REPO} -F p={PR_NUMBER} \
  --jq '.data.repository.pullRequest.reviewThreads.nodes | map(select(.isResolved==false))'
# Must return []. If non-empty, capture the thread ids for the recovery prompt below.
```

A thread can fail to resolve (permission boundary, already-resolved race). Re-querying after the loop is the only way to catch it.

**Recovery path — if the re-query returns > 0**: do NOT silently continue. Warn the user immediately via AskUserQuestion:

> "N thread(s) could not be resolved — IDs: [list the IDs]. The PR remains blocked under `required_review_thread_resolution: true` until they are resolved manually or via the GitHub UI. Submit the verdict anyway, or abort?"

Options: **Submit anyway** (proceed to Step 9 with a top-level comment flagging the unresolved IDs) / **Abort** (stop without submitting the verdict; surface the IDs so the user can resolve them via the UI and re-run `/review-pr`).

### Exception — dismissed prior reviews

When a prior REQUEST_CHANGES review was *dismissed* (state `DISMISSED`, distinct from being superseded by a fresh APPROVE), GitHub appears to auto-resolve the inline threads that belonged to it. Always fetch thread `isResolved` state via the GraphQL query above before running the mutation — they may already be done. A fresh APPROVE does NOT auto-resolve threads, so the mutation is still required there.

### Optional — post acknowledgment replies before resolving

For thoroughness, reply to each resolved thread with a brief `"✅ Resolved in {SHA}: {one-sentence explanation}"` before running the mutation. This gives the author auditable per-thread context. Skip when the verdict body already enumerates the resolutions thread-by-thread (a markdown table in the verdict body covers the same ground without per-thread spam).
