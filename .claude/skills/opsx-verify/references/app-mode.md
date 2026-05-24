# App Mode (`--app <slug>`)

Designed to satisfy the [retrofit playbook](../../../../.github/docs/claude/retrofit.md) "When the retrofit is done" checklist mechanically, without depending on `openspec status` (which only knows active changes — archived retrofits would be invisible to it).

App Mode is **read-only** — never modifies code, never invokes `/opsx-archive`, never proposes fixes interactively. It is a DoD audit.

---

## A1. Verify app prereqs

- App exists at `<workspace>/{slug}/`
- `{slug}/openspec/` is a directory
- Working tree state may be dirty — App Mode writes nothing

## A2. Enumerate retrofit ghost changes

```bash
find {app}/openspec/changes -maxdepth 3 -type d -name 'retrofit-*' | sort
```

Include both `{app}/openspec/changes/retrofit-*` (active — should normally be empty after archive) and `{app}/openspec/changes/archive/retrofit-*`. Anything matching is a "retrofit ghost change" for the report.

## A3. Per-retrofit structural check

For each retrofit folder `R`:

| Check | Expected | Failure level |
|---|---|---|
| `R/proposal.md` exists | always | CRITICAL |
| `R/tasks.md` exists | always | CRITICAL |
| All tasks `[x]` | always (retrofit convention — code already exists) | CRITICAL |
| `R/design.md` exists | reverse-spec runs (cluster/extend) only — annotate runs and cross-ref ghosts are exempt | WARNING for missing on reverse-spec; not flagged otherwise |
| `R/specs/{cap}/spec.md` exists | reverse-spec runs only | WARNING for missing on reverse-spec; not flagged otherwise |
| `@spec openspec/changes/{R-basename}` count in `lib/` + `src/` | annotate runs: large (≥ 50). Reverse-spec runs: ≥ 1 method per task. Private-helper inheritance retrofits: 0 is intentional — read `R/proposal.md` first to confirm | WARNING if 0 on a non-inheritance retrofit |

**Annotate-vs-reverse-spec detection:** if `R` matches `retrofit-*-annotate-*`, treat as annotate. Otherwise treat as reverse-spec unless `R/proposal.md` explicitly says "cross-ref" or "private helper".

## A4. App-level aggregate checks

### 1. Dangling `@spec` scan

Any `@spec openspec/changes/<X>` reference in `lib/`/`src/` whose `<X>` doesn't resolve to a folder under either `{app}/openspec/changes/` or `{app}/openspec/changes/archive/`.

```bash
grep -roh "@spec openspec/changes/[a-z0-9-]*" {app}/lib/ {app}/src/ \
  --include="*.php" --include="*.js" --include="*.ts" --include="*.vue" 2>/dev/null \
  | sed 's|^@spec openspec/changes/||' | sort -u | while read change; do
    [ -d "{app}/openspec/changes/$change" ] || [ -d "{app}/openspec/changes/archive/$change" ] || echo "DANGLING: $change"
  done
```

Any output is CRITICAL.

### 2. Symlink scan

Any symlink under `{app}/openspec/changes/` is an anti-pattern (legacy of the `2026-05-01-retrofit-X-2026-05-01` half-archive workflow).

```bash
find {app}/openspec/changes -maxdepth 1 -type l
```

Any output is CRITICAL.

### 3. Naming convention

Every retrofit folder must match `retrofit-{YYYY-MM-DD}-{descriptor}` (date right after `retrofit-`).

```bash
ls {app}/openspec/changes/archive/ | grep -E '^retrofit-' \
  | grep -vE '^retrofit-[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z][a-z0-9-]*$'
```

Any output is CRITICAL — usually means a retrofit was created before the convention switched (e.g. `retrofit-{descriptor}-{date}` order or the redundant `2026-05-01-retrofit-X-2026-05-01` form). Rename via `git mv` and update text references.

### 4. Cohort frontmatter coverage

Every capability that received **new retrofit-derived REQs** must carry the cohort flag on its master spec. Documentation-only retrofits do NOT require frontmatter — the cohort flag is for tracking REQ provenance, not annotation provenance.

**Step 1: classify each retrofit ghost change.** Walk every `R` and decide:
- **REQ-adding retrofit** — has `R/specs/{cap}/spec.md` with at least one `### REQ-NNN:` heading. The capability `{cap}` enters the cohort and MUST carry frontmatter.
- **Documentation-only retrofit** — no `specs/` delta, OR the `proposal.md` explicitly says one of: *"no new REQs"*, *"no new REQs needed"*, *"no new REQs drafted"*, *"no new REQs required"*, *"behaviors are fully covered"*. Examples: cross-capability annotation patches (b2b-crossrefs), private-helper inheritance retrofits (schema-hooks), scanner-misclassification cleanups (tenant-isolation-audit). These do NOT require cohort frontmatter on any capability.
- **Annotate retrofit** — `retrofit-{date}-annotate-{app}`. Never adds REQs; never requires frontmatter.

**Step 2: build the cohort set.** Union of `{cap}` values from REQ-adding retrofits only.

**Step 3: verify each cohort capability** has `retrofit: true` (cluster) or `retrofit_extensions: [...]` (extend) in `{app}/openspec/specs/{cap}/spec.md` frontmatter.

**Step 4: format check.** `retrofit_extensions` MUST be block YAML with bare REQ-IDs (per `/opsx-reverse-spec` SKILL.md Step 8). Inline `[REQ-005]` or quoted `["REQ-005"]` is WARNING; full requirement-text values are CRITICAL.

Missing cohort flag on a REQ-adding capability is CRITICAL — `sync_spec_content.py` won't tag the capability as retrofit cohort in Specter. Missing cohort flag on a documentation-only capability is **expected** and not a finding.

### 5. Coverage report freshness (informational only)

If `{app}/openspec/coverage-report.json` exists, compare its `generated_at` timestamp to the most recent retrofit ghost change date. Stale report ≠ failure, but worth reporting.

## A5. Generate app-level report

```markdown
## Retrofit Verify: {app}

### App-level checks
| Check | Status | Detail |
|---|:-:|---|
| Retrofit ghost changes | ✅ N found, M archived | Newest: <date> |
| Tasks completion | ✅ all [x] / ⚠️ N incomplete | |
| Dangling @spec paths | ✅ 0 / ❌ N | <list> |
| Symlinks under changes/ | ✅ 0 / ❌ N | <list> |
| Naming convention | ✅ N/N / ❌ N malformed | <list> |
| Cohort frontmatter | ✅ K/K / 🟡 K/K (missing: <caps>) | |
| Frontmatter format | ✅ block YAML / ⚠️ N inline / ❌ N full-text | |

### Per-retrofit details
(table with proposal/tasks/design/spec-delta/tasks-done/@spec-count per retrofit)

### Verdict
- ✅ **Retrofit complete** — all checks pass
- 🟡 **Retrofit partial** — only WARNINGs and informational items
- ❌ **Retrofit incomplete** — CRITICAL items remain (list them)
```

## A6. Final assessment

- ✅ **All clear**: state "Retrofit DoD passes for `{app}`. Safe to mark the playbook checklist complete."
- 🟡 **Partial**: list each gap with the exact remediation:
  - Cohort frontmatter missing → add `retrofit:` / `retrofit_extensions:` to `{app}/openspec/specs/{cap}/spec.md` and re-run `python3 concurrentie-analyse/scripts/sync_spec_content.py {app}`.
  - Naming-convention violation → `git mv` to canonical form + update text references.
  - Dangling `@spec` → either restore the missing change folder, or update the dangling annotations to point at an existing change.
- ❌ **Failed**: stop with a clear reason. Do NOT mark playbook checklist items as complete.
