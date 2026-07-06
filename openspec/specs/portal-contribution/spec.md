---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — ADR-046 provider class (external-employee + client audiences) + unit tests + standalone PHPUnit wiring (kind: code; depends on `portal-schemas`)

## Purpose

hrmq contributes to portaliq, the shared external portal for people without
Nextcloud accounts (hydra ADR-046 + 2026-07-06 amendment, contribution
contract v2): external employees get UUID-scoped self-service over their own
employee record, payslips, employment contracts, timesheets, expenses and
leave requests (with strict create whitelists for timesheet/expense/leave),
and clients get a read-only view over the timesheets whose billable hours
they review. The contribution is one plain, dependency-free provider class
(`OCA\Hrmq\Portal\PortalContributionProvider`, duck-typed by FQCN — inert
without portaliq) over the register surface shipped by the `portal-schemas`
capability.

## Requirements

Detailed requirements (REQ-PORT-001 … REQ-PORT-005) are defined in the
active change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: hrmq ships its portal contribution as one plain duck-typed provider (REQ-PORT-000)

The app MUST serve its entire portal contribution through the single plain,
dependency-free `OCA\Hrmq\Portal\PortalContributionProvider` class
(convention FQCN, duck-typed, inert without portaliq), whose manifests scope
every collection by a UUID domain-object reference resolved from the
server-managed claim map (`claims.hrmq.employeeId` / `claims.hrmq.clientId`)
— never a Nextcloud user id. No other portal logic, UI, endpoint or
dependency may exist in hrmq.

#### Scenario: Contribution surface is the single provider class

- GIVEN the hrmq codebase at HEAD
- WHEN the portal surface is inspected
- THEN `lib/Portal/PortalContributionProvider.php` is the only portal artefact, with no portaliq import, no info.xml dependency and no DI registration
- @e2e exclude backend-only contract class with no hrmq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)
