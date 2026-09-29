## 1. Schema and access

- [x] 1.1 Add `lib/Settings/register.d/hr-relations.json` with `EmployeeRelationsCase` and
      its lifecycle. Verify: `occ maintenance:repair` imports it.
- [x] 1.2 Declare the schema-level and property-level `authorization` of design D2 with the
      HR group from `compliance-roles-and-field-access`. Verify: integration test reading a
      case as HR (full), as the manager (kind and status only), as the subject (closed
      warning only) and as another employee (nothing).
- [x] 1.3 Default `retainedUntil` on `afsluiten` and add the schema to the retention flag.
      Verify: `occ humaniq:rules:audit` flags a seeded case past its date.

## 2. Pages

- [x] 2.1 Add `RelationsCases`, `RelationsCaseDetail` and `MijnMaatregelen`; a list on
      `EmployeeDetail`. Verify: `npm run check:manifest` exits 0.
- [x] 2.2 Seed the two cases. Verify: `npm run check:seed-refs` exits 0.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and a live read of a case as HR and as the employee's manager.

Notes from the build (2026-09-29): 1.2 was proven with OpenRegister's own PropertyRbacHandler,
ConditionMatcher and LifecycleAnnotationValidator in a harness outside the repository (HR full,
manager kind and status only, subject a closed warning only, another employee nothing: 28
assertions) and in the repository by RelationsCaseDeclarationTest; the live reads and the live
`occ humaniq:rules:audit` are in the PR's live-check recipe. 3.1: every scenario carries a
reason-bearing `@e2e exclude` naming its test.
