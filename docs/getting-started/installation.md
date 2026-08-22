---
sidebar_position: 1
description: Install Humaniq, satisfy the OpenRegister dependency, and run the first-run register import.
---

# Installation

## Dependency: OpenRegister

Humaniq owns no database tables of its own. `Employee`, `EmploymentContract`,
`PayrollRun`, `Payslip`, `WageTaxFiling`, `Timesheet`, `Expense`, and every
other HR object live in the OpenRegister `hrmq` register, and Humaniq's Vue
manifest pages read and write that register directly. **OpenRegister must
be installed before Humaniq.**

> The register slug is still `hrmq` and deliberately stays that way: the app
> was renamed, the register was not. OpenRegister resolves registers by slug,
> so renaming it would create a second, empty register and orphan every
> existing employee, contract and payslip.

Supported Nextcloud versions: 28–34. PHP 8.3+.

## Install from the app store

```bash
occ app:install humaniq
occ app:enable humaniq
```

Or, from a source checkout:

```bash
cd /var/www/html/custom_apps
git clone https://github.com/ConductionNL/humaniq.git humaniq
cd humaniq
composer install --no-dev
occ app:enable humaniq
```

## First-run register import

On install and on every upgrade, Humaniq's `InitializeRegister` post-migration
repair step runs automatically. It reads
`lib/Settings/humaniq_register.json`, deep-merges the modular schema fragments
under `lib/Settings/register.d/*.json` (payroll, leave, verzuim,
onboarding, recruiting, performance, org chart, assets, documents, and
more), and hands the merged result to OpenRegister to create or update the
`hrmq` register's schemas.

The import is idempotent — OpenRegister's per-register/per-schema
`version_compare` means re-running it on a routine upgrade is a no-op
unless a schema or fragment actually changed. If OpenRegister is not yet
installed when the repair step runs, it fails soft rather than blocking
the rest of the install.

To force a re-import (for example after manually editing a register
fragment in a development checkout), re-run the repair step:

```bash
occ maintenance:repair --include-expensive
```

## Verify the install

Once enabled, open Humaniq from the Nextcloud app switcher. The `Dashboard`
page (menu order 10) is the landing page; `Mijn HR` gives every logged-in
employee their own self-service view (see [Self-service](/docs/hr/self-service)).

If you plan to use the payroll engine, continue with [The payroll
engine](/docs/payroll/payroll-engine) — it explains the versioned tax-year
table Humaniq ships and the `occ humaniq:payroll:run` / `occ humaniq:payroll:verify`
commands.
