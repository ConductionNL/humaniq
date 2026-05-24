# Frontend Pattern Adherence + API/Browser Testing

Reference content extracted from per-change Steps 7 (Coherence: frontend gates) and 8 (API + browser testing). The procedural steps live in [SKILL.md](../SKILL.md) — this file holds the long-form commands and templates.

---

## Frontend Pattern Adherence — gates 10–13

Run if the change touched any `.vue`/`.js`/`.ts` files in `src/`. These four checks mirror the mechanical gates 10–13 from `scripts/run-hydra-gates.sh`. Each is a CRITICAL finding when violated — they map to ADR-004 hard rules.

### 1. Initial state, not DOM (gate-10)

```bash
grep -rnE "getElementById\\s*\\([^)]+\\)[^.]*\\.dataset\\b" src/ \
    --include='*.vue' --include='*.js' --include='*.ts' 2>/dev/null
```

If hits: Add CRITICAL: "DOM dataset read at <file>:<line> — server-side data must use `IInitialState::provideInitialState()` + `loadState()` from `@nextcloud/initial-state`"

### 2. No admin in vue-router (gate-11)

```bash
for f in src/router/index.js src/router/index.ts src/router.js src/router.ts; do
    [ -f "$f" ] || continue
    grep -nE "from\\s+['\"][^'\"]*(/Admin[A-Z][A-Za-z]*\\.vue|views/settings/)" "$f"
    grep -nE "path\\s*:\\s*['\"]/(settings|admin)\\b" "$f"
done
```

If hits: Add CRITICAL: "Admin settings component routed at <file>:<line> — security regression. Admin settings must be registered via `AdminSettings.php` only, never as a vue-router route"

### 3. NcSelect labels (gate-12)

```bash
find src -name '*.vue' | while read v; do
    tr '\n' ' ' < "$v" | grep -oE '<NcSelect[^>]*>' \
        | grep -vE '(input-label|inputLabel|aria-label-combobox|ariaLabelCombobox)' \
        | sed "s|^|$v: |"
done
```

If hits: Add CRITICAL: "NcSelect without `inputLabel`/`ariaLabelCombobox` at <file> — breaks WCAG 1.3.1 / 4.1.2; remove any manual `<label>` and use the built-in prop"

### 4. Modal/dialog file isolation (gate-13)

```bash
find src -name '*.vue' | grep -vE '^src/(modals|dialogs)/' | while read v; do
    grep -lE '<NcModal[ \t>/]|<NcDialog[ \t>/]' "$v" 2>/dev/null
done
```

If hits: Add CRITICAL: "Inline modal/dialog at <file> — extract to `src/modals/<Name>.vue` (NcModal) or `src/dialogs/<Name>.vue` (NcDialog) and import in the parent"

---

## API Testing

### a. Discover endpoints

Read `{app}/appinfo/routes.php` to find endpoints affected by this change. Cross-reference with the specs to identify which endpoints should exist.

### b. Test CRUD operations

For each affected resource endpoint, test with curl:

```bash
# CREATE
curl -s -u admin:admin -X POST -H "Content-Type: application/json" \
  -d '{"name":"Verify Test"}' http://nextcloud.local/index.php/apps/{app}/api/{resource}
# Returns 201 with created object including id

# READ
curl -s -u admin:admin http://nextcloud.local/index.php/apps/{app}/api/{resource}/{id}
# Returns 200 with full object; 404 for non-existent

# LIST
curl -s -u admin:admin http://nextcloud.local/index.php/apps/{app}/api/{resource}
# Returns 200 with array and pagination metadata

# UPDATE
curl -s -u admin:admin -X PUT -H "Content-Type: application/json" \
  -d '{"name":"Updated"}' http://nextcloud.local/index.php/apps/{app}/api/{resource}/{id}

# DELETE
curl -s -u admin:admin -X DELETE http://nextcloud.local/index.php/apps/{app}/api/{resource}/{id}
```

### c. Verify against spec scenarios

For each GIVEN/WHEN/THEN scenario in the specs, craft a curl request that exercises it. Check response codes, payloads, and error messages match expectations.

### d. NLGov compliance spot-check

Verify the basics:
- URLs use lowercase plural nouns with hyphens
- Collections include pagination metadata (`total`, `page`, `pages`)
- Error responses include `message` or `detail` field with proper HTTP status
- `Content-Type: application/json` on all responses

### e. Add findings

CRITICAL (endpoint broken/missing), WARNING (non-compliant), or SUGGESTION (improvement).

---

## Browser Testing

### a. Set up browser session

Use `browser-1` tools (`mcp__browser-1__*`):

```
1. browser_resize → width: 1920, height: 1080
2. browser_navigate → http://nextcloud.local/index.php/apps/{app}
3. If redirected to login:
   - browser_fill_form with username: admin, password: admin
   - Submit the form
4. browser_snapshot → confirm app loaded
```

### b. Test spec scenarios via browser

For each GIVEN/WHEN/THEN scenario from the specs:
- **GIVEN**: Navigate to the correct page, verify precondition state
- **WHEN**: Perform the action using `browser_click`, `browser_type`, `browser_fill_form`
- **THEN**: `browser_snapshot` to verify expected outcome, `browser_take_screenshot` with filename: `test-results/verify/{change-name}-{scenario-slug}.png`

### c. Monitor for errors during testing

- `browser_console_messages` (level: "error") after each action
- `browser_network_requests` to catch failed API calls (4xx/5xx)

### d. Test core flows relevant to the change

- CRUD: Create → verify in list → update → verify change → delete → verify removed
- Navigation: sidebar links, back/forward, deep linking
- Forms: required field validation, success feedback, cancel behavior
- Loading/error states: indicators, empty states, error messages

### e. Add findings

With screenshot evidence. CRITICAL for broken flows, WARNING for degraded UX, SUGGESTION for polish.
