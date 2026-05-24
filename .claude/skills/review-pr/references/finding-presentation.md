## Present findings in chat (Step 7)

**For single PR** — first review:
```
### Analysis: #{PR_NUMBER} — {title}  [Mode: {STRICTNESS_MODE}]

⚠️ CI failing (N checks): [check-name], [check-name]   ← omit banner if all checks pass
🔴 Blockers (N): [file:line] Title
🟡 Concerns (N): ...
🟢 Minor (N):    ...
```

Show the CI banner only when `{failingChecks}` is non-empty. If `{failingRequiredChecks}` is non-empty, mark the banner **Required checks failing** in bold. If only non-required checks fail, use plain **CI failing**.

**For single PR** — re-review:
```
### Re-review: #{PR_NUMBER} — {title}  [{N} new commits since {LAST_REVIEW.submitted_at}]

✅ Addressed since last review (N): [comment summary]
⚠️  Still open (N): [comment summary]
🆕 New findings (N): [file:line] Title
```

**For batch mode**: show a consolidated table (PR | Title | Mode | CI | 🔴 | 🟡 | 🟢 | Verdict) first, where CI is ✅ (all pass), ⚠️ (non-required failures), or 🚫 (required failures), then per-PR finding details in the same format as single-PR.

**Annotate pre-discussed findings** (`already_discussed_in` non-empty): append `_(discussed in merged PR #N)_` after the title. These are still included — prior discussion does not resolve them; the annotation informs the reviewer the author was likely aware.
