# PR Quality Pre-check

Full protocol for Step 2a. Runs per PR after Step 2 metadata is available, before
strictness selection.

---

## Description check — flag if ALL are true

- `body` is empty, `null`, whitespace-only, or fewer than 50 non-whitespace characters.
- PR is non-trivial: `changedFiles > 1` OR `additions > 30`.

---

## Title check — flag if the title is clearly a stub

- Fewer than 10 characters, OR
- Looks like a raw branch-name slug: all lowercase with only dashes/underscores and no
  spaces (e.g., `fix-bug`, `update-stuff`, `feature/thing`).

---

## If the description is missing/stub AND the PR is non-trivial

1. Post a top-level comment on the PR:

```bash
gh api repos/{OWNER}/{REPO}/issues/{PR_NUMBER}/comments \
  -X POST \
  -f body="🟡 **Missing PR description**

This PR makes non-trivial changes but has no description. Please add a description covering:
- What changed and why
- Any behavioral differences reviewers should know about
- Links to related issues, specs, or prior art

A reviewer cannot confidently verify intent against the diff without this context."
```

2. AskUserQuestion: "PR #{PR_NUMBER} — \"{TITLE}\" has no description. I've posted a
   comment asking the author to fill it in. Stop here and wait, or continue the review
   anyway?"
   - **Stop** → remove this PR from `{PR_LIST}`. If `{PR_LIST}` is now empty, end the
     skill run.
   - **Continue** → note the missing description as a 🟡 Concern in the analysis brief;
     proceed to Step 3.

---

## If only the title is a stub (description is present)

Include it as a 🟢 Minor in the analysis brief; do not stop.

---

## Batch mode

Collect all PRs with missing descriptions into a table and ask once — "N of M PRs have
no description. Stop those PRs, continue all, or select per PR?"

---

## Exception — branch-promotion PRs

When the PR's head→base looks like a branch-promotion (`development → beta`, `beta → main`,
`main → production`), a missing description is normal. Derive intent from:

1. `CHANGELOG.md` modifications in the PR (if any).
2. Commit messages (and bodies) of every commit in the PR.
3. `Merge pull request #N` lines in those commit messages — fetch the original PRs with
   `gh pr view N --repo {owner}/{repo}` for the original descriptions.

Use that aggregated context as the analysis brief instead of the PR body. Do **not** post
the missing-description comment for branch-promotion PRs.
