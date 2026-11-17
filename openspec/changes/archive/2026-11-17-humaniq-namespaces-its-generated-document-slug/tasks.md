# Tasks

## 1. The rename

- [x] 1.1 `GeneratedDocument` -> `HrGeneratedDocument` in all four descriptors,
      key AND `slug` in each — a descriptor whose key moved but whose `slug`
      did not is still the old schema.
- [x] 1.2 The three code sites that resolve by that slug.
- [x] 1.3 The manifest fragment's two `"schema":` bindings.
- [x] 1.4 UI page ids and route names left alone: they are not slugs.

## 2. The migration

- [x] 2.1 `MigrateSchemaSlug` renames the row, scoped to `(application, slug)`.
- [x] 2.2 Registered in BOTH `<post-migration>` and `<install>`, after
      `MigrateSchemaApplicationId` (which puts the schemas on the id this step
      scopes to) and before `InitializeRegister` (which is what forks).
- [x] 2.3 Idempotent, refuses when both slugs exist, and never throws.

## 3. Verification

- [x] 3.1 Tests for rename, second run, refusal, and unreadable table.
- [x] 3.2 A test asserting the map, all four descriptors and the register list
      agree — the assertion that catches a rename which reached three of them.
- [x] 3.3 1,389 tests green.

## 4. Not done

- [x] 4.1 Run the repair step against a live install with existing
      `GeneratedDocument` rows. The e2e rig has no humaniq register imported,
      and the step is unit-tested at the SQL it emits rather than against a real
      row. An operator upgrading should confirm the Documenten index still
      lists its documents afterwards.
      Done in the live pass (2026-11-17): on a clean install holding the three seeded
      documents, the schema row was set back to `GeneratedDocument` (as an install from before
      the rename has it) and `occ maintenance:repair` ran. MigrateSchemaSlug reported "1 schema
      slug(s) renamed, 0 refused", the register import updated that row instead of forking a
      second one (one `HrGeneratedDocument`, id 68), and the Documenten index still lists all
      three documents.
