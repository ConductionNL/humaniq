# Content-Type Guides

When a PR adds or modifies certain content types, ConductionNL has canonical
authoring conventions documented in `ConductionNL/.github/docs/claude/`. Step 5d
detects these patterns in the PR diff, fetches the relevant guides into
`/tmp/pr-{PR_NUMBER}-guides/`, and the analysis subagent compares the new content
against the conventions.

This is **supplementary** to the mechanical gates and the standing review checklist
— it does not replace them. Where overlaps exist (most notably frontend / Vue
files), the agent must defer to the gate's pre-flagged findings and only add new
observations.

## Fetching Protocol

Create the staging directory once per PR:

```bash
mkdir -p /tmp/pr-{PR_NUMBER}-guides
```

For each matched pattern below, run the listed curl command. Commands are
idempotent. On network error or 404, log and continue without the guide — do not
block the review.

Capture the full list of files touched by the PR:

```bash
gh pr view {PR_NUMBER} --repo {OWNER}/{REPO} --json files \
  --jq '.files[].path' > /tmp/pr-{PR_NUMBER}-files.txt

# Optional: also collect the subset of files that are net-new (additions, no deletions).
# Most patterns trigger on additions AND modifications; only use the new-files list when
# the per-pattern section below explicitly says so.
gh pr view {PR_NUMBER} --repo {OWNER}/{REPO} --json files \
  --jq '.files[] | select(.deletions == 0 and .additions > 0) | .path' \
  > /tmp/pr-{PR_NUMBER}-new-files.txt
```

**Triggering policy — added vs modified:** authoring conventions and high-risk
checklists apply equally to new files AND modifications of existing files. If
someone edits an existing spec to add a new Requirement, the spec convention
still applies; if someone edits an existing Vue component to introduce a
deprecated pattern, the frontend convention still applies; if someone modifies
an existing migration, the migration checklist applies (and the modification
itself becomes a 🟡 Concern — see §7.1 below). All per-pattern triggers below
therefore use `/tmp/pr-{PR_NUMBER}-files.txt`.

The new-files list `/tmp/pr-{PR_NUMBER}-new-files.txt` is kept around for any
future check whose risk profile is genuinely "only new code matters" — none of
the patterns below currently use it, but having both lists ready means a future
addition can opt in without re-deriving them.

---

## 1. OpenSpec specs

**Trigger:** any file matching `openspec/specs/**/spec.md` or `openspec/changes/**/specs/**/spec.md` in `pr-{PR_NUMBER}-files.txt`.

```bash
if grep -qE "^openspec/(changes/.+/specs/.+/spec\.md|specs/.+/spec\.md)$" \
       /tmp/pr-{PR_NUMBER}-files.txt; then
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/writing-specs.md \
        -o /tmp/pr-{PR_NUMBER}-guides/writing-specs.md
    curl -sf https://raw.githubusercontent.com/ConductionNL/hydra/main/openspec/schemas/conduction/schema.yaml \
        -o /tmp/pr-{PR_NUMBER}-guides/conduction-spec-schema.yaml
fi
```

**Brief addendum:**

> The PR touches OpenSpec specs. Read the staged guides:
> - `/tmp/pr-{PR_NUMBER}-guides/writing-specs.md` — ConductionNL's spec authoring conventions (Requirement/Scenario structure, delta format, ADR linking, …)
> - `/tmp/pr-{PR_NUMBER}-guides/conduction-spec-schema.yaml` — the structural schema the spec must conform to
>
> For every changed spec, verify:
> - Delta files use the correct `## ADDED Requirements` / `## MODIFIED Requirements` / `## REMOVED Requirements` headers
> - Each `### Requirement:` is followed by a SHALL/MUST/SHOULD statement and ≥1 `#### Scenario:` in GIVEN/WHEN/THEN form
> - ADR references resolve (org-wide in `hydra/openspec/architecture/`, repo-specific in `<app>/openspec/architecture/`)
> - Cross-spec references between change directories resolve to real paths
>
> When multiple changes in the same PR add deltas to the same canonical spec, check that they don't collide on the same Requirement name (a real source of merge conflicts at archive time).

---

## 2. ADRs (Architecture Decision Records)

**Trigger:** any file matching `openspec/architecture/adr-*.md` in `pr-{PR_NUMBER}-files.txt`.

```bash
if grep -qE "^openspec/architecture/adr-.+\.md$" /tmp/pr-{PR_NUMBER}-files.txt; then
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/writing-adrs.md \
        -o /tmp/pr-{PR_NUMBER}-guides/writing-adrs.md
fi
```

**Brief addendum:**

> The PR adds or modifies an ADR. Read `/tmp/pr-{PR_NUMBER}-guides/writing-adrs.md` for the ConductionNL ADR convention.
>
> Verify:
> - The ADR number is contiguous with the existing series (no duplicates, no gaps unless documented as reserved). Hydra's org-wide ADRs are 001–023 + 024–032 — check the corresponding directory.
> - Required sections present: Context / Decision / Consequences (or whatever the guide mandates).
> - If a new ADR overrides an existing one, the older ADR is updated with a "Superseded by ADR-N" link.
> - For org-wide ADRs (in `hydra/openspec/architecture/`), check that no app-repo carries a stale duplicate copy under its own `.claude/openspec/architecture/` — past incidents have come from such drift.

---

## 3. Skills

**Trigger:** any `SKILL.md` under `.claude/skills/<name>/SKILL.md` that is added **or modified** in the PR. Description tweaks, line-count changes, frontmatter edits all need the same convention check, and (in hydra) all of them require regenerating `skill-level-overview.html`.

```bash
if grep -qE "^\.claude/skills/[^/]+/SKILL\.md$" /tmp/pr-{PR_NUMBER}-files.txt; then
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/writing-skills.md \
        -o /tmp/pr-{PR_NUMBER}-guides/writing-skills.md
fi
```

**Brief addendum:**

> The PR adds or modifies a skill. Read `/tmp/pr-{PR_NUMBER}-guides/writing-skills.md` for the skill structure convention (frontmatter, description triggers, references/ folder, examples/).
>
> Verify:
> - `SKILL.md` has the required frontmatter (`name`, `description`, `metadata.category`, `metadata.tags`).
> - `description` is specific enough that the dispatcher can pick the right skill (avoid vague "helper for X" wording).
> - For modifications: changes to the `description` should be reflected in the regenerated `skill-level-overview.html` (hydra-only — see check below).
> - For modifications: changes to the workflow body should keep `SKILL.md` within recommended line count (typically under 500 lines — split into `references/` files otherwise).
> - If the skill spawns subagents, the brief explicitly forbids posting/mutating state where applicable.

**Hydra-specific check:** when the PR is on the `hydra` repo AND any `SKILL.md` is added OR modified, the skill overview HTML must be regenerated by running `./.claude/skills/update-skill-overview.sh`:

```bash
if [ "{REPO}" = "hydra" ] && \
   grep -qE "^\.claude/skills/[^/]+/SKILL\.md$" /tmp/pr-{PR_NUMBER}-files.txt && \
   ! grep -qE "^\.claude/skills/skill-level-overview\.html$" /tmp/pr-{PR_NUMBER}-files.txt; then
    # Emit a pre-flagged finding into the analysis brief.
    echo "PREFLAG: 🟡 Concern | skill-overview-not-regenerated | .claude/skills/<changed-skill>/SKILL.md | SKILL.md was added or modified without re-running ./.claude/skills/update-skill-overview.sh — skill-level-overview.html is not in this PR. Run the script and amend." \
        >> /tmp/pr-{PR_NUMBER}-preflag.txt
fi
```

The analysis subagent must include this preflagged finding verbatim in its output.

---

## 4. Documentation

**Trigger:** any file matching `docs/**/*.md` or top-level `README.md` (any repo).

```bash
if grep -qE "^(docs/.+\.md|README\.md)$" /tmp/pr-{PR_NUMBER}-files.txt; then
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/writing-docs.md \
        -o /tmp/pr-{PR_NUMBER}-guides/writing-docs.md
fi
```

**Brief addendum:**

> The PR adds or modifies documentation. Read `/tmp/pr-{PR_NUMBER}-guides/writing-docs.md` for the documentation convention.
>
> Verify:
> - Front matter / title / TOC structure matches the convention.
> - Cross-links to other docs use relative paths that resolve.
> - Code blocks declare the language for syntax highlighting.
> - Screenshots referenced from `docs/` have alt text and live in the expected folder.

---

## 5. Playwright / E2E tests

**Trigger:** any file matching `tests/e2e/**/*.spec.{ts,js}` or `playwright.config.{ts,js}`.

```bash
if grep -qE "^(tests/e2e/.+\.spec\.(ts|js)|playwright\.config\.(ts|js))$" \
       /tmp/pr-{PR_NUMBER}-files.txt; then
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/playwright-setup.md \
        -o /tmp/pr-{PR_NUMBER}-guides/playwright-setup.md
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/testing.md \
        -o /tmp/pr-{PR_NUMBER}-guides/testing.md
fi
```

**Brief addendum:**

> The PR adds E2E tests. Read both staged guides.
>
> Verify (from `testing.md` + `playwright-setup.md`):
> - Tests use stable `data-testid` selectors (per ADR-030) rather than CSS class chains.
> - `beforeEach` / fixture setup matches the documented Conduction Playwright project layout.
> - No hard-coded sleeps; uses `page.waitFor*` / locator auto-waiting.
> - Test names describe the user-facing behavior, not the implementation.

---

## 6. Frontend Vue components

**Trigger:** any `.vue` file under `src/` that is added or modified.

```bash
if grep -qE "^src/.+\.vue$" /tmp/pr-{PR_NUMBER}-files.txt; then
    curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/frontend-standards.md \
        -o /tmp/pr-{PR_NUMBER}-guides/frontend-standards.md
fi
```

**Brief addendum:**

> The PR adds or modifies a Vue component. Read `/tmp/pr-{PR_NUMBER}-guides/frontend-standards.md` for the broader frontend conventions (component layout, BEM naming, CSS variables, NL Design System tokens). The conventions apply equally to new files and to changes inside an existing component — a modification that introduces a deprecated pattern is just as bad as a new file with the same pattern.
>
> **IMPORTANT — overlap with existing checks:** The four ADR-004 hard rules (DOM dataset reads, admin-router exposure, NcSelect labels, inline modals) are already covered by mechanical gates 10–13 (Step 5c) and the "Frontend (ADR-004)" section of `review-checklist.md`. Do NOT re-flag those — gates' pre-flagged findings are authoritative. `frontend-standards.md` adds the conventions that mechanical scans cannot catch (component file layout, prop naming, scoped styles, i18n key naming, design tokens).
>
> Add only findings the gates did not cover.

---

## 7. High-Risk Patterns (no external guide — inline checklists)

These trigger an inline checklist baked into the analysis brief — there is no
`writing-X.md` to fetch. The point is to make the agent attend to a higher-risk
file type with a concrete checklist rather than rely on its general reasoning.

### 7.1 PHP migrations — `lib/Migration/Version*.php`

Trigger:
```bash
grep -qE "^lib/Migration/Version.+\.php$" /tmp/pr-{PR_NUMBER}-files.txt
```

Inline checklist:
- Both `changeSchema()` and `postSchemaChange()` are present (or one is documented as not needed).
- Schema changes are idempotent — `if (!$schema->hasTable(...))` guards on every `createTable`, `addColumn`, `addIndex`.
- Data migrations are batched (no unbounded `UPDATE … WHERE 1=1` on a large table).
- The migration does not delete data without an explicit comment justifying it.
- A matching DOWN path exists, OR the migration is documented as forward-only with a reason.
- Foreign keys / indexes added on hot columns have explicit names so they can be dropped cleanly.

**Special note for modifications to an already-merged migration:** modifying a migration file that has shipped is itself suspicious — installs that already ran the old version won't re-execute the new code. Flag any modification to a `Version*.php` file as a 🟡 Concern unless the change is purely cosmetic (comments, formatting) or the modification adds a `try/catch` around an existing operation. The right fix for "I made a mistake in a shipped migration" is usually a new follow-up migration, not editing the old one.

### 7.2 CI/CD workflows — `.github/workflows/*.yml`

Trigger:
```bash
grep -qE "^\.github/workflows/.+\.ya?ml$" /tmp/pr-{PR_NUMBER}-files.txt
```

Inline checklist:
- All `uses:` actions pin to a commit SHA (not a tag/branch) for security-sensitive workflows.
- `permissions:` block is present and minimal (default-deny, grant only what's needed).
- No `pull_request_target` without a documented justification (this trigger gives untrusted PRs write access).
- Secrets are not echoed, logged, or assigned to step outputs that could leak.
- Concurrency / cancel-in-progress is configured for long-running matrices.

### 7.3 Dockerfiles — any `Dockerfile`, `Dockerfile.*`, `images/*/Dockerfile`

Trigger:
```bash
grep -qE "(^|/)Dockerfile(\..+)?$" /tmp/pr-{PR_NUMBER}-files.txt
```

Also fetch the org docker guide:
```bash
curl -sf https://raw.githubusercontent.com/ConductionNL/.github/main/docs/claude/docker.md \
    -o /tmp/pr-{PR_NUMBER}-guides/docker.md
```

Inline checklist:
- Base image is pinned to a SHA-digest or a specific patch tag (not `:latest`).
- `USER` directive sets a non-root user before `CMD` / `ENTRYPOINT`.
- No secrets baked into image layers (`COPY` of `.env`, hard-coded tokens, etc.).
- Multi-stage build is used when build deps differ from runtime deps.
- `--cap-drop ALL` posture from CLAUDE.md security constraints is consistent with the image's runtime profile.

### 7.4 Dependency manifests — `composer.json`, `package.json`

Trigger:
```bash
grep -qE "^(composer\.json|package\.json)$" /tmp/pr-{PR_NUMBER}-files.txt
```

Inline checklist:
- A matching `composer.lock` / `package-lock.json` / `yarn.lock` update is in the PR.
- Each added dependency has a license compatible with EUPL-1.2 (ADR-014). MIT / Apache-2.0 / BSD / EUPL — fine. GPL / AGPL — flag.
- Each added dependency is justified — either by a code change in the same PR that uses it, or by a comment in the PR body. Unjustified deps are a supply-chain risk.
- Version constraints use caret (`^X.Y.Z`) or tilde (`~X.Y.Z`), not `*`.
- Note that mechanical gate 4 (`composer audit`) already covers CVE detection — do not re-flag that.

### 7.5 i18n — `l10n/*.js`, `translationfiles/*`

Trigger:
```bash
grep -qE "^(l10n/[a-z_]+\.js|translationfiles/.+)$" /tmp/pr-{PR_NUMBER}-files.txt
```

Inline checklist (ADR-007):
- English (`en.js` or the English translation source) is the source of truth.
- Dutch (`nl.js`) is required for every key added in English. If only one is updated, flag.
- Key names follow the convention from ADR-007 (typically `t('appname', 'English source string')` with no programmatic key construction).
- No hard-coded user-facing strings outside the i18n files.

### 7.6 App manifest — `appinfo/info.xml`

Trigger:
```bash
grep -qE "^appinfo/info\.xml$" /tmp/pr-{PR_NUMBER}-files.txt
```

Inline checklist:
- If the `<version>` tag changed, a matching `CHANGELOG.md` entry exists for that version.
- Semver: major bumps require explicit justification in the PR body; patches do not.
- `<dependencies>` and `<requirements>` declarations match what the code actually requires (don't list Nextcloud 30 if you import an NC 31 API).
- New `<navigations>` / `<settings>` entries align with the controller / route changes in the same PR.

---

## Output Contract

Step 5d populates `{PR_MAP}[PR_NUMBER]` with:

- `stagedGuides`: array of `{ trigger, guideFiles: [path, ...] }` — used by the analysis brief to instruct the agent which guides to read.
- `preflaggedFindings`: array of `{ severity, title, file, body }` — preflagged findings emitted by deterministic checks (e.g. the hydra skill-overview check). Agent must include verbatim.
- `inlineChecklists`: array of `{ pattern, checklist }` — for high-risk patterns with no external guide.

Step 6 (analysis-brief.md) embeds all three into the subagent prompt.
