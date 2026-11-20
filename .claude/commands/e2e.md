---
description: Run the Playwright end-to-end tests against a running instance
---

Run the Playwright suite in `tests/e2e`.

1. Make sure a Nextcloud with humaniq and OpenRegister is running, for example
   `docker compose -f docker-compose.yml up -d --build` (serves `http://localhost:8080`).
2. Set the instance under test; there is no default:
   `export PLAYWRIGHT_BASE_URL=http://localhost:8080`
3. `npm run test:e2e`

Report failing specs with the assertion that failed.
