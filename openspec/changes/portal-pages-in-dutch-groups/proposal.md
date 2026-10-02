# Portal pages in Dutch, under a resident-facing group

## Why

Woo round 3 (hydra woo-citizen-journey, Ruben 2026-10-02): the site menu showed humaniq's pages under the app name
"Humaniq" and in English ("Timesheets to review", "My payslips", "Log hours"), next to Dutch pages from other apps.
The manager's page printed its name twice: a `##` heading in the intro and a collection named differently.

## What changes

- Every portal page carries a `group` (portaliq's group contract): "Werk en uren" for an external employee, a client
  and a manager, "Vacatures" for a candidate, "Uw nieuwe baan" for a new hire, "Uw vroegere baan" for a former
  employee. Audiences without declared pages get the pages portaliq made when none were declared (the create action
  for the collection's schema, the list, the selected row), so the screens stay as they were.
- Every collection and action label is Dutch. The manager's page and its collection share the name "Urenstaten van
  uw team", and the intro drops its own heading, so the page shows one heading.

## Impact

- `lib/Portal/PortalContributionProvider.php` and its test. No register change, no new l10n key (portal labels are
  Dutch literals, like the other contributing apps).
