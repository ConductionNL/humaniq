# In-Progress Issue Status Sweep

After the per-issue lifecycle suggestions in Step 5.6, run a broader sweep over all GitHub issues currently in `In Progress` on the org's Project-V2 Kanban, plus all issues the user touched today. For each, decide whether today's work warrants posting a status-update comment.

## Why this exists

Stakeholders read in-progress issues for status; without an active sweep, work that advances an in-progress issue stays invisible there. The natural daily report-out only touches issues the user has already commented on — this step adds the ones they should have commented on.

## Discovery query: org Kanban "In Progress"

ConductionNL project metadata (cache, refresh if it ever drifts):

| Field | Value |
|-------|-------|
| Project ID | `PVT_kwDOAsg4w84BUZis` |
| Project number | `4` ("Conduction KanBan") |
| Status field ID | `PVTSSF_lADOAsg4w84BUZiszhBh7q8` |
| `In Progress` option ID | `47fc9ee4` |

To re-derive the IDs (run once on a new project / new machine):

```bash
gh api graphql -f query='
{
  organization(login: "ConductionNL") {
    projectsV2(first: 20) { nodes { id number title } }
  }
}'
gh api graphql -f query='
{
  node(id: "PVT_kwDOAsg4w84BUZis") {
    ... on ProjectV2 {
      fields(first: 20) {
        nodes {
          ... on ProjectV2SingleSelectField { id name options { id name } }
        }
      }
    }
  }
}'
```

Listing issues with Status = "In Progress":

```bash
gh api graphql -f query='
{
  node(id: "PVT_kwDOAsg4w84BUZis") {
    ... on ProjectV2 {
      items(first: 100) {
        nodes {
          id
          content {
            __typename
            ... on Issue {
              number title state url
              repository { nameWithOwner }
              assignees(first: 5) { nodes { login } }
            }
          }
          fieldValues(first: 20) {
            nodes {
              ... on ProjectV2ItemFieldSingleSelectValue {
                field { ... on ProjectV2SingleSelectField { name } }
                name
              }
            }
          }
        }
      }
    }
  }
}' \
  --jq '.data.node.items.nodes[]
        | select(.fieldValues.nodes[]?
                 | select(.field.name=="Status" and .name=="In Progress"))
        | {repo: .content.repository.nameWithOwner, number: .content.number,
           title: .content.title, assignees: [.content.assignees.nodes[]?.login]}'
```

## "Touched today" set

```bash
gh search issues --commenter "@me" --updated ">=$TARGET_DATE" --limit 30 \
  --json number,title,url,repository,updatedAt
gh search issues --author    "@me" --updated ">=$TARGET_DATE" --limit 30 \
  --json number,title,url,repository,updatedAt
```

Deduplicate by `(repository.nameWithOwner, number)`, then union with the In-Progress set.

## Per-issue decision flow

For each `(repo, number)` in the union:

```
Has the user already commented on this issue today?
├── Yes → skip silently (already covered by the touched-today bucket / Step 7)
└── No  → does any of today's work touch this issue's scope?
          ├── No  → print "{repo}#{N}: no update needed" in the chat overview, skip
          └── Yes → draft a brief Dutch status comment, show, ask Yes/Edit/Skip,
                    post via `gh api repos/$OWNER/$REPO/issues/$N/comments -X POST`
```

### Has the user commented today?

```bash
SINCE="${TARGET_DATE}T00:00:00Z"
gh api "repos/$OWNER/$REPO/issues/$N/comments?per_page=50" \
  --jq '[.[] | select(.user.login == "'"$GH_LOGIN"'" and .created_at >= "'"$SINCE"'")] | length'
```

Non-zero → skip.

### Does today's work touch this issue's scope?

Heuristic — match against any of:

- The issue's `repository.nameWithOwner` is in `{REPOS_WITH_ACTIVITY}` (today's commits touched it directly).
- Today's PR titles / commit subjects contain the issue's number, slug, or principal nouns from its title.
- Today's newly-created issues link back to this issue (`Volgt op #{N}`, `Refs #{N}`).
- The issue is explicitly mentioned in `{USER_CONTEXT}` (Step 1).

If none of these hit, print `no update needed` and skip.

## Draft format

Brief Dutch comment — single status update, not a daily report-out:

```markdown
### {DISPLAY_DATE} — status update

{1–3 sentences describing how today's work touches this issue}

- {Optional bullet: link to the PR / commit / new issue, with full URL}
```

Always show the draft and ask before posting.

## Anti-patterns

| Anti-pattern | Why wrong |
|--------------|-----------|
| Posting on every In-Progress issue regardless of relevance | Bloats threads, drowns real updates |
| Re-posting when the user already has a today's comment | Duplicate noise |
| Treating issue-number mentions in PR titles as definitive scope match | Verify the PR body too — sometimes numbers are coincidental |
| Auto-posting without confirmation | Hard rule: every comment requires `AskUserQuestion` Yes |
| Including assignee status changes ("assigned to X") | This step is for content updates, not field changes |
