---
kind: code
---

# Install published HR configurations from the store

## Why

The matrix row `plt-template-store` asks whether you can install ready-made HR configurations
others published, such as a leave policy. humaniq has a Store page (`src/manifest.json`, page
`Store`, type `store`) over OpenRegister's store plane: it lists configuration sets and installs
them through OpenRegister; with no registry configured it shows humaniq's own items and makes
no network call. No humaniq spec describes it, so the row counts as unspecified.

The board `humaniq/HuInrichten` draws a Sjablonen step in the setup wizard: three templates
from the template store, published by other municipalities, chosen during setup and shown in
the closing summary. The board is the UI canon, so the spec follows it.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `plt-template-store` | Install ready-made HR configurations others have published, such as a leave policy. | built: Store page over OpenRegister's store plane; no Sjablonen step in setup |

## What changes

- **Two requirements** in `first-time-setup`: the Store page lists and installs published
  configuration sets through OpenRegister, and the setup wizard offers a Sjablonen step that
  installs the chosen sets the same way.

## Non-goals

- No store backend in humaniq: OpenRegister hosts the store plane (ADR-114, ADR-080).
- Publishing humaniq's own HR sets (a leave policy, an onboarding flow) is content work, not
  part of this change.
- The other wizard steps the board draws (administrations, way of working, language) belong to
  their own changes.
