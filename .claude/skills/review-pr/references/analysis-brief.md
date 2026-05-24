# Analysis Subagent Brief

Use this when spawning the analysis subagent in Step 6.

## Hard rules the subagent prompt MUST open with

The first lines of every analysis brief MUST be a "DO NOT" block. Without it the subagent reads the rest of the skill file, sees Steps 8–9, and self-dispatches the posting — bypassing the user confirmation in Step 7.

> ## DO NOT
> - DO NOT post inline comments (no `gh api .../pulls/N/comments -X POST`)
> - DO NOT submit a formal review (no `gh api .../pulls/N/reviews -X POST`)
> - DO NOT mutate any GitHub state on this PR
> - Your sole output is the findings list as text. The orchestrator handles posting after user confirmation.

This block is non-negotiable. Copy it verbatim into every brief.

## What to include in the brief

- PR title, body, changed file list, additions/deletions
- `{STRICTNESS_MODE}` and its rules (from [strictness-modes.md](strictness-modes.md))
- Path to the staged diff: `/tmp/pr-{PR_NUMBER}-diff.txt`
- For re-review: path to new-commits diff `/tmp/pr-{PR_NUMBER}-new-diff.txt`,
  list of `{PRIOR_COMMENTS}` (file, line, body), list of `{NEW_COMMITS}` messages,
  list of `{THREAD_REPLIES}`
- The review checklist from [review-checklist.md](review-checklist.md)
- The comment format rules from [comment-format.md](comment-format.md)
- **Pre-flagged mechanical-gate findings** (from Step 5c): if `{PR_MAP}[PR_NUMBER].gateFindings`
  is non-empty, list each finding with its gate number, name, reason, and file:line. Each gate
  failure has a corresponding per-gate skill (`hydra-gate-<name>`) documenting the fix; the
  agent must include every gate finding as a 🔴 blocker in its findings list, with the comment
  body pointing the author at the per-gate skill. **Do not re-derive these mechanically** —
  the gate script is authoritative. The agent's job is to confirm the line/range is in the
  diff and write a useful inline comment; no re-scanning needed.
- If `{PR_MAP}[PR_NUMBER].gateStatus == "skipped"`, note that mechanical gates could not run
  for this PR (e.g. the user wasn't inside a clone of the repo) and the agent should perform
  the gate-equivalent checks from the review-checklist by hand.
- **Staged content-type guides** (from Step 5d): if `{PR_MAP}[PR_NUMBER].stagedGuides`
  is non-empty, list each entry with its trigger pattern and the local paths of the
  guides in `/tmp/pr-{PR_NUMBER}-guides/`. Instruct the agent to:
  1. Read each staged guide before reviewing files that match the trigger pattern.
  2. Compare the new content against the documented convention; flag deviations per strictness mode.
  3. For overlap-prone patterns (notably `frontend-standards.md` ↔ mechanical gates 10–13),
     **defer to the gates' pre-flagged findings**. Do not re-flag what the gates already
     caught — add only new observations the gates cannot detect (style, naming, design
     tokens, broader conventions).
  See the per-pattern brief addenda in [content-type-guides.md](content-type-guides.md).
- **Preflagged content-type findings** (from Step 5d): if `{PR_MAP}[PR_NUMBER].preflaggedFindings`
  is non-empty (e.g. hydra skill-overview not regenerated), include each entry verbatim
  in the output — these are deterministic checks the agent should not re-derive.
- **Inline checklists for high-risk patterns** (from Step 5d): if
  `{PR_MAP}[PR_NUMBER].inlineChecklists` is non-empty (migrations, workflows,
  Dockerfile, deps, i18n, info.xml), include each checklist in the prompt.
  The agent walks the checklist against the matching files and emits findings
  per strictness mode.
- **If `{HAS_MERGED_CONTEXT}` is true**: path to `{MERGED_CONTEXT_FILE}` and the
  cross-reference instructions from [merged-pr-context.md](merged-pr-context.md)

## What to instruct it to do

1. Read the diff file(s) and any referenced source files needed for context
2. Verify each concern — check actual code, do not summarize
3. Apply `{STRICTNESS_MODE}` rules:
   - Which severity levels to include
   - How to handle uncertain findings (escalate or downgrade per mode)
4. For each finding, identify the exact `file:line` in the **new file** using hunk
   headers (`@@ -old,len +new,len @@`): context lines at hunk start are new-file
   lines `new`…`new+(context-1)`; added lines follow
5. **For re-review**: additionally assess each prior comment:
   - **Addressed**: new commit clearly fixes the issue → mark for reply
   - **Partially addressed**: issue reduced but not fully fixed → keep as open with note
   - **Unresolved**: no relevant new commit → keep open
   - **New issue in new commits**: include as new finding
6. Report:
   - For re-review: `{RESOLVED}` list, `{STILL_OPEN}` list, `{NEW_FINDINGS}` list
   - For first review: `{FINDINGS}` list
   - Each entry: severity, title, file, line, body text, `already_discussed_in` list
