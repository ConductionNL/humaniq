# AGENTS.md

Guide for coding agents working in this repository. Every command below is defined in
`composer.json` or `package.json` in this repo.

## What this app is

Humaniq is a Nextcloud app for open-source HR and payroll administration for Dutch and EU
employers, built on the OpenRegister data layer. It ships a versioned HR, labour law and
wage-tax compliance rule corpus with a rule engine, and HR features such as the employee
master, payroll, timesheets and expenses.

## Setup

- Install dependencies: `composer install` and `npm ci` (Node version in `.nvmrc`).
- Evaluation stack (Nextcloud, OpenRegister, PostgreSQL, Redis):
  `docker compose -f docker-compose.yml up -d --build`, then open
  `http://localhost:8080/apps/humaniq/`. See the header of `docker-compose.yml`.
- Demo stack: `docker compose -f humaniq-compose.yaml up -d`, then open
  `http://localhost:8618/apps/humaniq/`. This is a demo, not a development environment:
  it does not bind-mount your checkout. See `docs/Installation/demo-environment.md`.

## Build

- `npm run build` builds the production frontend bundle with webpack.
- `npm run dev` builds a development bundle; `npm run watch` rebuilds on change.

## Test

- PHP unit tests (PHPUnit, `tests/Unit`): `composer test:unit`
- PHP coverage report: `composer test:coverage`
- JavaScript tests (node test runner, `tests/js`): `npm run test:js`
- End-to-end tests (Playwright, `tests/e2e`): `npm run test:e2e`. Set
  `PLAYWRIGHT_BASE_URL` to the instance under test; there is no default.

## Lint and quality

- Full PHP gate: `composer check:strict` (lint, phpcs, phpmd, psalm, phpstan, tests).
  `composer check` is the quicker subset (lint, phpcs, psalm, unit tests).
- Single tools: `composer phpcs`, `composer phpmd`, `composer psalm`, `composer phpstan`,
  `composer cs:check`; auto-fix with `composer phpcs:fix` or `composer cs:fix`.
- Frontend: `npm run lint` (ESLint) and `npm run stylelint`.
- Manifest and translation checks: `npm run check:manifest`, `npm run check:l10n`,
  `npm run check:boot-integrity`.

## OpenSpec flow

Work is spec-driven (`openspec/config.yaml`).

1. Propose a change in `openspec/changes/<change-name>/` with `proposal.md`, `design.md`,
   `tasks.md` and delta specs under `specs/`.
2. When the change is implemented, its requirements land in `openspec/specs/<capability>/spec.md`.
3. The finished change moves to `openspec/changes/archive/<date>-<change-name>/`.

## Architecture decisions

ADRs for this app live in `openspec/architecture/` (`adr-001-information-architecture.md`,
`adr-101-jurisdiction-packs.md`, `adr-102-leave-approval-guard-is-an-or-lifecycle-guard.md`).

## Repo conventions

- Humaniq owns no database tables. HR objects live in the OpenRegister `hrmq` register,
  defined in `lib/Settings/humaniq_register.json` plus the fragments in
  `lib/Settings/register.d/`.
- Procedures are configuration: object lifecycles are declared with
  `x-openregister-lifecycle` in the register schemas, and the payroll calculation chain is a
  declarative jurisdiction pack (`lib/Standards/packs/nl-2026.pack.json`) run by the
  interpreter in `lib/Payroll/Dsl/`. A new tax year is a data change.
- Pages are manifest config: `src/manifest.json` plus the fragments in `src/manifest.d/`
  (merge rules in `src/manifest.d/README.md`). Prefer a declarative page over a bespoke Vue view.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example
`feat(payroll): ...` or `fix(leave): ...`. See `CONTRIBUTING.md`.
