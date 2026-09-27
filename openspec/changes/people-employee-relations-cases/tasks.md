## 1. Schema and access

- [ ] 1.1 Add `lib/Settings/register.d/hr-relations.json` with `EmployeeRelationsCase` and
      its lifecycle. Verify: `occ maintenance:repair` imports it.
- [ ] 1.2 Declare the schema-level and property-level `authorization` of design D2 with the
      HR group from `compliance-roles-and-field-access`. Verify: integration test reading a
      case as HR (full), as the manager (kind and status only), as the subject (closed
      warning only) and as another employee (nothing).
- [ ] 1.3 Default `retainedUntil` on `afsluiten` and add the schema to the retention flag.
      Verify: `occ humaniq:rules:audit` flags a seeded case past its date.

## 2. Pages

- [ ] 2.1 Add `RelationsCases`, `RelationsCaseDetail` and `MijnMaatregelen`; a list on
      `EmployeeDetail`. Verify: `npm run check:manifest` exits 0.
- [ ] 2.2 Seed the two cases. Verify: `npm run check:seed-refs` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and a live read of a case as HR and as the employee's manager.
