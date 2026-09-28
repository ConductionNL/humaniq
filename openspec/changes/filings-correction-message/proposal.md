---
kind: code
---

# A correction for an earlier wage tax return

## Why

A payroll officer who finds a mistake in a month that is already filed has to tell the
Belastingdienst. Until the end of that tax year's correction window the fix travels as a
correction in a later return (a correctie over een eerder tijdvak); a closed year needs a
separate correction message. humaniq can send a return, but its `corrigeren` transition on
`LoonaangifteFiling` only resets a sent filing to `concept`. Its own description calls that "a
simplified placeholder: full correctie-berichten (VL/IB-correcties) are a future spec". This
change is that spec.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `fil-correction` | File a correction for an earlier wage tax return. | `no`, building: the transition exists, no correction is built |

### Competitors rated yes

- AFAS Profit: "corrections in the wage tax return, as correction returns or in a full return,
  can be traced in Profit" (https://help.afas.nl/help/NL/SE/Pay_Taxes_Corr.htm).
- Visma Raet YouServe: release notes cover the aanvullende loonaangifte
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- HR2day: "after a message from the tax authority you see what to correct"
  (https://data.maglr.com/1697/issues/66612/785292/index.html).
- Loket.nl: "initiate a payroll tax return for a closed year" in its public API
  (https://developer.loket.nl/ApiDocs#tag/Initiate-payroll-tax-return).

## What changes

- **A correction is a new filing, never a rewrite.** `corrigeren` on a sent filing creates a
  new `LoonaangifteFiling` with `filingType: correctie`, `corrects` pointing at the sent one,
  and the same period. The sent filing stays `verzonden` and read-only, so the record of what
  was sent survives.
- **The correction carries the difference.** When the correction is made ready, humaniq
  compares the payslips of the corrected period as they are now (after a retro recalculation)
  with the snapshot the sent message was built from, and stores per employee what changed.
- **Inside the year or after it.** A correction for a period in the current tax year is marked
  to travel with the next regular return; one for a closed year becomes its own correction
  message.
- **The old transition goes.** `corrigeren` from `verzonden` to `concept` is replaced, so no
  sent filing can be reopened in place.

## Capabilities

### New capabilities

- `loonaangifte-correction`: correcting a sent wage tax return by a linked correction filing.

## Impact

- `lib/Settings/register.d/hr-objects.json`: `LoonaangifteFiling` gains `corrects`,
  `correctionLines`, `correctionRoute`; the `corrigeren` transition changes.
- `lib/Lifecycle/` a new guard on `corrigeren`; `lib/Service/` a correction service.
- `src/manifest.d/hr-objects.json`: the filing detail page shows what a correction corrects.

## Depends on

- `filings-wage-tax-message`: the correction compares against the snapshot the sent message
  was rendered from. Build that change first.

## Out of scope

- Sending. As for the regular return, the employer uploads the file.
