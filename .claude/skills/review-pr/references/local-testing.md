# Local Testing Integration

Full protocol for Step 7a (Offer Local Testing). Step 7a points here once the user has
agreed to test the PR's changes against a local clone before the verdict is submitted.

---

## 1. Detect testable changes

From `{PR_MAP}[PR_NUMBER].changedFiles` (the file list fetched in Step 2), classify each
path into layers:

| Layer | Signals (path matches) |
|---|---|
| **backend** | `*.php`, `lib/**`, `appinfo/**`, `tests/**`, `*.py`, `*.rb`, `composer.json`, `phpunit.xml` |
| **frontend** | `*.vue`, `*.ts`, `*.tsx`, `*.js`, `*.jsx`, `*.css`, `*.scss`, `src/**`, `package.json`, `vite.config.*` |
| **infra** | `Dockerfile*`, `docker-compose*`, `.github/workflows/**`, `Makefile`, `scripts/**` |
| **docs-only** | `*.md`, `docs/**`, `LICENSE`, `CHANGELOG*` |

Set `{TESTABLE_LAYERS}` = the set of non-empty layers excluding `docs-only`. If empty →
report "Only docs/config changed — local testing offers no signal" and skip Step 7a
entirely.

---

## 2. Repo discovery

Try the following lookup order to locate a local clone of `{OWNER}/{REPO}`:

1. **Current working directory** — if `git remote get-url origin` resolves to `{OWNER}/{REPO}`,
   use the CWD.
2. **Common workspace roots** — search depth 3 under each of these for a `.git` dir whose
   `origin` remote matches `{OWNER}/{REPO}`:
   - `~/` (top-level personal repos)
   - `~/nextcloud-docker-dev/workspace/server/apps-extra/` (Nextcloud apps)
   - `~/nextcloud-docker-dev/workspace/server/` (NC core)
   - `~/wordpress-docker/` (WordPress plugins)
   - Any path in `$HYDRA_LOCAL_REPO_DIRS` (colon-separated, optional override)
3. **Honor a single match** — if exactly one clone is found, use it directly.
4. **Multiple matches** — present each with the head-branch name (`git -C <path> branch
   --show-current`) via AskUserQuestion: "Which clone of `{OWNER}/{REPO}` should I use?"
   Offer "Cancel local testing" as an explicit option.
5. **No match** — ask the user via AskUserQuestion: "No local clone of `{OWNER}/{REPO}`
   found. Clone it now?"
   - **Yes — into `~/nextcloud-docker-dev/.../apps-extra/`** (NC apps default)
   - **Yes — into `~/`** (top-level)
   - **Yes — custom path** (free-text follow-up)
   - **No, skip local testing**

When cloning, use `gh repo clone {OWNER}/{REPO} <dest>`. Store the resolved path as
`{LOCAL_REPO_PATH}`.

---

## 2a. Docker readiness

Most local tests require Docker. Check before building the plan:

1. **Docker daemon up?** `docker ps >/dev/null 2>&1`. Exit 0 → up, proceed. Non-zero → down.
2. **If Docker is down**, AskUserQuestion: "Docker isn't running. Start it now?"
   - **Start it for me** — try the host-appropriate command:
     - Linux: `sudo systemctl start docker`
     - WSL: `sudo service docker start`
     - macOS: `open -a Docker`, then poll `docker ps` every 3s up to 60s (Docker Desktop typically needs 30–60s to come up; consistent with the NC-stack timeout in step 3 below)
   - **I'll start it manually** — pause until the user confirms.
   - **Skip local testing** — fall through Step 7a, jump to Step 8.
   Re-run `docker ps` after each option; do not proceed until it returns 0.
3. **Nextcloud app start-up** — locate the `nextcloud-docker-dev` clone by the **git
   remote**, not by folder name (a user may have cloned it into a renamed dir). Walk up
   each parent of `{LOCAL_REPO_PATH}`; for each parent that is itself a git repo, run
   `git -C <parent> remote get-url origin 2>/dev/null` and treat it as the NC dev root
   if the URL's last path segment is `nextcloud-docker-dev` or
   `nextcloud-docker-dev.git` (any owner — upstream is `juliusknorr/nextcloud-docker-dev`
   but forks like `<user>/nextcloud-docker-dev` are equally valid). Also require a
   `docker-compose.yml` to exist in that parent. Store the path as `{NC_DEV_ROOT}`.
   Example: `~/some-renamed-dir/workspace/server/apps-extra/openregister` →
   walked-up parent `~/some-renamed-dir` has origin
   `https://github.com/<user>/nextcloud-docker-dev` → `{NC_DEV_ROOT} = ~/some-renamed-dir`.
   If no parent matches, skip the NC stack start-up and proceed (the user can start the
   stack manually). On a match, AskUserQuestion: "Start the NC dev stack
   (`docker compose up -d nextcloud proxy` in `{NC_DEV_ROOT}`)?" — Yes / No / Already
   running. On Yes: run the command and poll `docker compose ps` until the proxy reports
   healthy (max ~60s); abort with the docker logs on failure.
4. **Non-NC repos** — the plan's environment-setup step is responsible for its own runtime
   (e.g. `docker compose up -d` in the repo's own root if it has its own compose file).

Both the Docker daemon start and the NC stack start are **prerequisite** plan steps —
include them as steps 0 and 0a of the plan template in section 4.

---

## 3. Map detected layers to test skills

Build `{TEST_SKILLS}` from `{TESTABLE_LAYERS}` and `{IS_SECURITY_SENSITIVE}` (from Step 4a):

| Layer / signal | Skill candidates | When to suggest |
|---|---|---|
| frontend | `test-app`, `test-accessibility` | Vue / UI changes; accessibility on any markup or NL Design System change |
| backend | `test-functional`, `test-api` | Service/controller logic; API endpoints touched |
| backend OR frontend | `test-regression` | Broad changes that could break existing behaviour |
| infra | `test-functional` (smoke) | CI/Docker/script changes — at minimum boot-and-curl |
| `{IS_SECURITY_SENSITIVE}` | `test-security`, `persistence-audit` | Always — auth, RBAC, tokens, workflows |
| perf-sensitive (large data flows, indexes, joins, loops over many rows) | `test-performance` | If the PR touches loops over user data, queries, or aggregations |
| `changedFiles > 15` OR cross-layer changes | `test-counsel` | Multi-persona sweep instead of single agent |

Mark each candidate as **recommended** (clear fit) or **optional** (might catch something).
Only recommended skills go into the plan by default; the user can add optional ones in
the edit step.

---

## 4. Plan template

Present this structure (filled in with the detected layers, repo path, and skills):

```
### Local test plan for #{PR_NUMBER} — {title}

Repo: {LOCAL_REPO_PATH}
Layers touched: {TESTABLE_LAYERS}
Recommended skills: {recommended list}
Optional skills:    {optional list}

Steps:
 0. Ensure Docker is running ({command picked per host from section 2a})
 0a. (NC app only) cd {NC_DEV_ROOT} && docker compose up -d nextcloud proxy
 1. cd {LOCAL_REPO_PATH} && git fetch origin pull/{PR_NUMBER}/head:pr-{PR_NUMBER}
 2. git checkout pr-{PR_NUMBER}
 3. {dependency install — composer install / npm ci / make install — pick from repo signals}
 4. {build step if needed — npm run build, composer dump-autoload}
 5. Run each recommended /test-* skill against the running instance
 6. Manual focus checks tied to findings from Step 7:
    - {one bullet per 🔴 finding the user wants to verify}
 7. Capture pass/fail/notes per step
```

---

## 5. Confirm with the user

AskUserQuestion: "Plan ready — proceed, edit, or cancel?"

- **Proceed** → execute steps in order, halt on first hard failure (env setup) and surface
  the error; soft failures (a test skill flagging issues) feed into findings.
- **Edit** → let the user reshape the plan (drop steps, add manual checks, switch skills).
  Re-present the revised plan and ask again.
- **Cancel** → skip to Step 8 with no local-test findings.

---

## 6. Integrate results into findings

For every issue discovered during local testing:

- If it maps to a specific file:line in the diff → add to
  `{PR_MAP}[PR_NUMBER].findings` with `source: "local-test"` and the standard
  🔴/🟡/🟢 severity. Step 8 posts it as an inline comment like any other finding.
- If it is PR-level (env setup fails, build fails, smoke-test fails repo-wide) → post as
  a top-level issue comment (same mechanism as Step 2a / persistence-audit summary) and
  link it from the verdict body in Step 9.

Add a short summary block to the Step 9 verdict body so the author sees the local-test
outcome alongside the code findings:

> "Local verification: checked out PR locally, ran {skills}. Result: {pass / N issues
> found — see findings}."

---

## Guardrails

- **Never modify the local repo's checked-out branch without confirmation** — the user
  may have uncommitted work. Always check `git status` before fetch + checkout; if the
  worktree is dirty, ask before stashing.
- **Never run destructive setup commands silently** — `composer install`, `npm ci`,
  `clean-env`-style resets must be in the plan and approved.
- **Never run local tests against production environments** — confirm the local instance
  is a dev/test instance before running any skill that writes data.
- **Always restore the original branch on completion** — `git checkout -` after testing,
  unless the user asked to stay on the PR branch.
- **Skip Step 7a entirely if the user declines** — do not ask again per-PR after a global
  "No" in batch mode.
