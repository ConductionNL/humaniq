# Official Belastingdienst test cases — slot

The fixtures in `tests/fixtures/payroll-2026/*.json` are **self-consistent**:
their expected values were computed from `lib/Standards/tables/nl-2026.json`
by the same equation chain the engine implements (design.md D2), cross-checked
by hand for the anchor case and by `BalancingInvariantTest` for the rest. They
prove the implementation matches the *documented* chain — they do **not**
prove the chain matches the law (README.md's non-certification disclaimer).

This directory is the marked slot for the actual Belastingdienst
"loonheffingstabellen proefberekeningen" (official test cases published
alongside the Rekenvoorschriften) once obtained. When available, drop them in
here verbatim as `official/<case-name>.json` using the same fixture shape as
the sibling directory:

```json
{
  "name": "<official case name/reference>",
  "input": {
    "grossMonthly": 0.00,
    "taxTableColor": "wit",
    "dateOfBirth": "YYYY-MM-DD",
    "loonheffingskortingToegepast": true,
    "awfTariff": "low",
    "aofTariff": "laag",
    "whkPercentage": 1.52,
    "period": "YYYY-MM"
  },
  "expected": {
    "loonheffing": 0.00,
    "arbeidskorting": 0.00,
    "volksverzekeringen": 0.00,
    "zvw": 0.00,
    "awf": 0.00,
    "aof": 0.00,
    "wko": 0.00,
    "whk": 0.00,
    "werknemersverzekeringen": 0.00,
    "employerCharges": 0.00,
    "vakantiegeldReserved": 0.00,
    "nettoPay": 0.00
  }
}
```

`PayrollCalculatorTest` picks up every `*.json` file in this directory the
same way it picks up the sibling self-consistent fixtures, so dropping a file
in here is the entire integration step — no test code changes needed.

Status: **empty** (no official cases obtained yet). This is the certification
gap named in the README disclaimer — closing it is tracked as a fast-follow,
not implied away.
