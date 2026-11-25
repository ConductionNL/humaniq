---
kind: code
---

## Why

Every humaniq page waited for `GET /api/setup/status` before the shell asked for the page's own
data: nextcloud-vue's CnAppRoot reads the setup status first, so setup status, saved views and
the object list ran one after the other. On the sandbox reference stack that request took
~110-120 ms at the head of the chain on every page load (hydra ADR-124 page budget).
nextcloud-vue#2378 lets a page carry the status as initial state `setup_status`.

## What Changes

- `PageController::index()` carries, for an administrator, the document `SetupController::status()`
  answers, as initial state `setup_status`. Nobody else gets it carried; their shell asks the
  endpoint as before, so nobody reads more than the endpoint gives them.
- SetupController is resolved lazily and a failure carries nothing (ADR-083 rule 3).
- Takes effect once humaniq runs a nextcloud-vue release that carries #2378; until then the shell
  ignores the carried state.

## Impact

- `lib/Controller/PageController.php`, `tests/Unit/Controller/PageControllerTest.php`.
- Spec `first-time-setup`: REQ-FTS-008.
