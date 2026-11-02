<?php

/**
 * Leave Calendar Service
 *
 * Projects approved absence (LeaveRequest/SickLeaveCase) onto one configured
 * shared Nextcloud calendar as all-day VEVENTs (design.md D1-D6). humaniq holds
 * no calendar storage of its own — the sync is a stateless, idempotent
 * upsert/remove pass driven on operator demand (occ humaniq:calendar:sync), not
 * an event listener or background job.
 *
 * The CalDAV store is reached exclusively through a duck-typed,
 * container-resolved `OCA\DAV\CalDAV\CalDavBackend` (string class name,
 * `mixed`, try/catch + `method_exists` probes — the exact
 * `PayrollGLPostService` idiom). The investigated OCP surface
 * (`\OCP\Calendar\ICreateFromString`, since 23.0.0) is create-only on NC
 * 28-34 and rejects a duplicate UID with `BadRequest`, so it cannot
 * implement the upsert-on-resync / remove-on-reject contract this feature
 * needs (design.md Context, D1). Any miss (backend unresolvable, config
 * unset, calendar deleted/URI wrong) degrades to a recorded
 * `skipped-no-calendar` outcome — never an exception, nothing above INFO in
 * the log — and humaniq gains no info.xml/composer dependency on the calendar
 * or dav apps.
 *
 * AVG boundary (design.md D5): rendered events carry only a fixed-format
 * SUMMARY (`Verlof — {naam}` / `Afwezig — {naam}`) and dates. `reason`,
 * `rejectionReason`, `leaveType` (health-adjacent enum values include
 * `sick`/`care`/`parental`), any WvP/case field, a DESCRIPTION property, and
 * any ATTENDEE/ORGANIZER (which would trigger iMIP scheduling mail) never
 * reach the calendar.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Upserts/removes approved-absence VEVENTs on the configured shared calendar.
 */
class LeaveCalendarService {

	/**
	 * The duck-typed CalDAV backend's FQCN, resolved from the container by
	 * string (design.md D1) — never a `use` import, so humaniq carries no
	 * composer/info.xml dependency on the dav app.
	 *
	 * @var string
	 */
	private const CALDAV_BACKEND_CLASS = 'OCA\DAV\CalDAV\CalDavBackend';

	/**
	 * @var string
	 */
	private const LEAVE_SCHEMA = 'LeaveRequest';

	/**
	 * @var string
	 */
	private const SICK_SCHEMA = 'SickLeaveCase';

	/**
	 * @var string
	 */
	private const EMPLOYEE_SCHEMA = 'Employee';

	/**
	 * Object-URI/UID prefix for LeaveRequest-derived events (design.md D2).
	 *
	 * @var string
	 */
	private const LEAVE_URI_PREFIX = LeaveCalendarEventRef::LEAVE_URI_PREFIX;

	/**
	 * Object-URI/UID prefix for SickLeaveCase-derived events (design.md D2).
	 *
	 * @var string
	 */
	private const SICK_URI_PREFIX = LeaveCalendarEventRef::SICK_URI_PREFIX;

	/**
	 * ObjectService findAll() page cap, mirroring RuleAuditService::LIMIT.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService/CalDavBackend resolution.
	 * @param SettingsService $settingsService Target-calendar config (design.md D6).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run the sync: upsert one all-day VEVENT per in-scope approved
	 * LeaveRequest / SickLeaveCase, remove events of LeaveRequests that left
	 * scope, and reconcile orphaned `humaniq-`-prefixed events whose source was
	 * hard-deleted from the register (design.md D1-D6).
	 *
	 * @param string|null $from Optional ISO date (Y-m-d). Bounds the upsert set
	 *                          to LeaveRequests whose endDate is on/after this
	 *                          date and hersteld SickLeaveCases whose
	 *                          recoveredDate is on/after this date; open
	 *                          SickLeaveCases (no end date to bound on) and
	 *                          the removal/reconciliation passes are always
	 *                          unbounded (design.md D2/REQ-LC-005/REQ-LC-007).
	 *
	 * @return array<int, array<string, mixed>> One outcome row per touched
	 *                                          source, or a single
	 *                                          `skipped-no-calendar` row.
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-003
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-006
	 */
	public function sync(?string $from = null): array {
		$principal = $this->settingsService->getLeaveCalendarPrincipal();
		$uri = $this->settingsService->getLeaveCalendarUri();
		if ($principal === '' || $uri === '') {
			return [$this->skipOutcome('leave_calendar_principal/leave_calendar_uri is niet geconfigureerd.')];
		}

		$backend = $this->resolveBackend();
		if ($backend === null) {
			return [$this->skipOutcome('De CalDAV-back-end kon niet worden opgelost (dav-app afwezig of onverwachte methodesignatuur).')];
		}

		$calendar = $this->probeCalendar($backend, $principal, $uri);
		if ($calendar === null) {
			return [$this->skipOutcome(sprintf('Kalender "%s" niet gevonden voor principal "%s".', $uri, $principal))];
		}

		$target = new CalendarTarget(backend: $backend, calendarId: $calendar['id'], principal: $principal, calendarUri: $uri);
		$run = new LeaveCalendarRun(target: $target, employeesById: $this->buildEmployeeIndex(), from: $from);

		$liveIds = ['leave' => $this->syncSources($run, 'leave'), 'sick' => $this->syncSources($run, 'sick')];
		if ($liveIds['leave'] === null || $liveIds['sick'] === null) {
			$this->logger->warning('LeaveCalendarService: weesreconciliatie overgeslagen; het register kon niet volledig worden gelezen.');
			return $run->results();
		}

		$this->reconcileOrphans($run, $liveIds);

		return $run->results();
	}//end sync()

	/**
	 * Sync every LeaveRequest (`leave`) or SickLeaveCase (`sick`) in the
	 * register and return the ids seen, or null when the register could not
	 * be read, so the orphan reconciliation never runs on a missing id set.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param string $type `leave` or `sick`.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function syncSources(LeaveCalendarRun $run, string $type): ?array {
		$rows = $this->tryLoadAll($type === 'leave' ? self::LEAVE_SCHEMA : self::SICK_SCHEMA);
		if ($rows === null) {
			return null;
		}

		$ids = [];
		foreach ($rows as $row) {
			$id = $this->sourceId($row);
			if ($id === '') {
				continue;
			}

			$ids[] = $id;
			if ($type === 'leave') {
				$this->syncLeave($run, $row);
				continue;
			}

			$this->syncSick($run, $row);
		}

		return $ids;
	}//end syncSources()

	/**
	 * The id of a LeaveRequest or SickLeaveCase row, or '' when it has none.
	 *
	 * @param array<string, mixed> $row The source object.
	 *
	 * @return string
	 */
	private function sourceId(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end sourceId()

	/**
	 * Upsert or remove the event for one LeaveRequest (design.md D2/D5,
	 * REQ-LC-002/REQ-LC-005): `approved` upserts (bounded by the run's
	 * `from` on endDate), any other status removes the event if it exists.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param array<string, mixed> $leave The LeaveRequest object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function syncLeave(LeaveCalendarRun $run, array $leave): void {
		$ref = new LeaveCalendarEventRef(type: 'leave', sourceId: $this->sourceId($leave));
		$status = (string)($leave['status'] ?? '');

		if ($status !== 'approved') {
			$this->removeEvent($run, $ref);
			return;
		}

		$startDate = trim((string)($leave['startDate'] ?? ''));
		$endDate = trim((string)($leave['endDate'] ?? ''));
		if ($startDate === '' || $endDate === '') {
			$run->record($this->outcome('leave', $ref->sourceId, 'failed', 'LeaveRequest mist startDate/endDate; kan niet gesynchroniseerd worden.'));
			return;
		}

		if ($run->from !== null && $this->dateOnOrAfter($endDate, $run->from) === false) {
			// Out of the --from bound; an already-synced event stays untouched.
			return;
		}

		$dtstart = $this->compactDate($startDate);
		$dtendExclusive = $this->compactDate($this->addDays($endDate, 1));
		if ($dtstart === null || $dtendExclusive === null) {
			$run->record($this->outcome('leave', $ref->sourceId, 'failed', 'LeaveRequest startDate/endDate is geen geldige datum.'));
			return;
		}

		$employeeId = (string)($leave['employeeId'] ?? '');
		$summary = 'Verlof — ' . $this->employeeName($run->employeesById, $employeeId);

		$this->upsertEvent($run, new LeaveCalendarEvent(ref: $ref, dtstart: $dtstart, dtendExclusive: $dtendExclusive, summary: $summary));

	}//end syncLeave()

	/**
	 * Upsert the event for one SickLeaveCase (design.md D4/D5,
	 * REQ-LC-003/REQ-LC-004): `gemeld` (open) rolls DTEND to today+1 on every
	 * sync; `hersteld` closes at recoveredDate+1 and is kept, never removed;
	 * a `hersteld` case with a null recoveredDate is skipped with a warning
	 * rather than guessed. Sick cases are never deleted by this method —
	 * removal only ever happens via orphan reconciliation on hard delete
	 * (design.md D4).
	 *
	 * @param LeaveCalendarRun $run The sync run; its `from` bound applies to hersteld cases only.
	 * @param array<string, mixed> $sick The SickLeaveCase object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-003
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-004
	 */
	private function syncSick(LeaveCalendarRun $run, array $sick): void {
		$ref = new LeaveCalendarEventRef(type: 'sick', sourceId: $this->sourceId($sick));

		$firstSickDay = trim((string)($sick['firstSickDay'] ?? ''));
		if ($firstSickDay === '') {
			$run->record($this->outcome('sick', $ref->sourceId, 'failed', 'SickLeaveCase mist firstSickDay; kan niet gesynchroniseerd worden.'));
			return;
		}

		$dtendExclusiveSource = $this->sickEndExclusive($run, $sick);
		if ($dtendExclusiveSource === null) {
			return;
		}

		$dtstart = $this->compactDate($firstSickDay);
		$dtendExclusive = $this->compactDate($dtendExclusiveSource);
		if ($dtstart === null || $dtendExclusive === null) {
			$run->record($this->outcome('sick', $ref->sourceId, 'failed', 'SickLeaveCase firstSickDay/recoveredDate is geen geldige datum.'));
			return;
		}

		$employeeId = (string)($sick['employeeId'] ?? '');
		$summary = 'Afwezig — ' . $this->employeeName($run->employeesById, $employeeId);

		$this->upsertEvent($run, new LeaveCalendarEvent(ref: $ref, dtstart: $dtstart, dtendExclusive: $dtendExclusive, summary: $summary));

	}//end syncSick()

	/**
	 * The exclusive end date (Y-m-d) of a SickLeaveCase event (design.md D4),
	 * or null when the case is not synced: an open (gemeld) case is a
	 * rolling bar through today, never bounded by --from; a hersteld case
	 * ends the day after recoveredDate, is skipped with an outcome row when
	 * recoveredDate is missing, and is left alone when it recovered before
	 * the run's `from` bound.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param array<string, mixed> $sick The SickLeaveCase object.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-004
	 */
	private function sickEndExclusive(LeaveCalendarRun $run, array $sick): ?string {
		if ((string)($sick['status'] ?? '') !== 'hersteld') {
			return $this->addDays(gmdate('Y-m-d'), 1);
		}

		$recoveredDate = trim((string)($sick['recoveredDate'] ?? ''));
		if ($recoveredDate === '') {
			$run->record($this->outcome('sick', $this->sourceId($sick), 'skipped', 'SickLeaveCase is hersteld maar recoveredDate ontbreekt; overgeslagen (geen gok).'));
			return null;
		}

		if ($run->from !== null && $this->dateOnOrAfter($recoveredDate, $run->from) === false) {
			return null;
		}

		return $this->addDays($recoveredDate, 1);
	}//end sickEndExclusive()

	/**
	 * Create or update one VEVENT (design.md D2): probe
	 * `getCalendarObjectByUID` scoped to the configured calendar; absent ⇒
	 * create; present ⇒ diff the rendered DTSTART/DTEND/SUMMARY against the
	 * stored event and update only when they differ (avoids etag churn on a
	 * no-op sync — the idempotency invariant tasks.md/spec require). Without
	 * the Employee index nothing is written, so a failed name lookup never
	 * replaces names on the calendar with the role word.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param LeaveCalendarEvent $event The desired event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 */
	private function upsertEvent(LeaveCalendarRun $run, LeaveCalendarEvent $event): void {
		$ref = $event->ref;
		if ($run->employeesById === null) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'skipped', 'Medewerkersnamen konden niet worden geladen; afspraak niet aangemaakt of bijgewerkt.'));
			return;
		}

		$lookup = $this->lookupEvent($run->target, $ref);
		if ($lookup['error'] !== null) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'failed', 'Kon bestaand kalenderobject niet opzoeken: ' . $lookup['error']));
			return;
		}

		if ($lookup['path'] === null) {
			$run->record($this->createEvent($run->target, $event));
			return;
		}

		$existingIcs = $this->fetchCalendarData($run->target, $ref->objectUri());
		if ($existingIcs !== null && $this->veventUnchanged($existingIcs, $event) === true) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'unchanged', 'Geen wijziging.'));
			return;
		}

		$run->record($this->updateEvent($run->target, $event));

	}//end upsertEvent()

	/**
	 * Look up an event's stored path by UID, scoped to the configured
	 * calendar (design.md D1).
	 *
	 * @param CalendarTarget $target The calendar target.
	 * @param LeaveCalendarEventRef $ref The event.
	 *
	 * @return array{path: mixed, error: string|null} The stored path (null when absent), or the lookup's error message.
	 */
	private function lookupEvent(CalendarTarget $target, LeaveCalendarEventRef $ref): array {
		$backend = $target->backend;
		try {
			$path = (method_exists($backend, 'getCalendarObjectByUID') === true)
				? $backend->getCalendarObjectByUID($target->principal, $ref->uid(), $target->calendarUri)
				: null;
		} catch (\Throwable $e) {
			return ['path' => null, 'error' => $e->getMessage()];
		}

		return ['path' => $path, 'error' => null];
	}//end lookupEvent()

	/**
	 * Create the calendar object of a new event.
	 *
	 * @param CalendarTarget $target The calendar target.
	 * @param LeaveCalendarEvent $event The desired event.
	 *
	 * @return array<string, mixed> The outcome row.
	 */
	private function createEvent(CalendarTarget $target, LeaveCalendarEvent $event): array {
		$ref = $event->ref;
		try {
			if (method_exists($target->backend, 'createCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::createCalendarObject is not available.');
			}

			$target->backend->createCalendarObject($target->calendarId, $ref->objectUri(), $this->renderIcs($event));
		} catch (\Throwable $e) {
			return $this->outcome($ref->type, $ref->sourceId, 'failed', 'Aanmaken kalenderafspraak mislukt: ' . $e->getMessage());
		}

		return $this->outcome($ref->type, $ref->sourceId, 'created', 'Kalenderafspraak aangemaakt.');
	}//end createEvent()

	/**
	 * Overwrite the calendar object of a changed event.
	 *
	 * @param CalendarTarget $target The calendar target.
	 * @param LeaveCalendarEvent $event The desired event.
	 *
	 * @return array<string, mixed> The outcome row.
	 */
	private function updateEvent(CalendarTarget $target, LeaveCalendarEvent $event): array {
		$ref = $event->ref;
		try {
			if (method_exists($target->backend, 'updateCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::updateCalendarObject is not available.');
			}

			$target->backend->updateCalendarObject($target->calendarId, $ref->objectUri(), $this->renderIcs($event));
		} catch (\Throwable $e) {
			return $this->outcome($ref->type, $ref->sourceId, 'failed', 'Bijwerken kalenderafspraak mislukt: ' . $e->getMessage());
		}

		return $this->outcome($ref->type, $ref->sourceId, 'updated', 'Kalenderafspraak bijgewerkt.');
	}//end updateEvent()

	/**
	 * Delete the event of a source that left scope (design.md D2,
	 * REQ-LC-005) — a no-op (`unchanged`) when no event exists.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param LeaveCalendarEventRef $ref The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function removeEvent(LeaveCalendarRun $run, LeaveCalendarEventRef $ref): void {
		$lookup = $this->lookupEvent($run->target, $ref);
		if ($lookup['error'] !== null) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'failed', 'Kon kalenderobject niet opzoeken voor verwijdering: ' . $lookup['error']));
			return;
		}

		if ($lookup['path'] === null) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'unchanged', 'Geen kalenderafspraak om te verwijderen.'));
			return;
		}

		$error = $this->deleteObject($run->target, $ref->objectUri());
		if ($error !== null) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'failed', 'Verwijderen kalenderafspraak mislukt: ' . $error));
			return;
		}

		$run->record($this->outcome($ref->type, $ref->sourceId, 'removed', 'Kalenderafspraak verwijderd.'));

	}//end removeEvent()

	/**
	 * Delete one calendar object.
	 *
	 * @param CalendarTarget $target The calendar target.
	 * @param string $objectUri The object URI.
	 *
	 * @return string|null The error message, or null once deleted.
	 */
	private function deleteObject(CalendarTarget $target, string $objectUri): ?string {
		try {
			if (method_exists($target->backend, 'deleteCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
			}

			$target->backend->deleteCalendarObject($target->calendarId, $objectUri);
		} catch (\Throwable $e) {
			return $e->getMessage();
		}

		return null;
	}//end deleteObject()

	/**
	 * Orphan reconciliation (design.md D2, REQ-LC-005): list the configured
	 * calendar's objects and delete every `humaniq-leave-*.ics` / `humaniq-sick-*.ics`
	 * URI whose embedded uuid is not among the given (full, unbounded) live
	 * id sets. Objects without the `humaniq-` prefix (manually created by users)
	 * are never touched.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param array{leave: array<int, string>, sick: array<int, string>} $liveIds All currently-existing LeaveRequest and SickLeaveCase ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function reconcileOrphans(LeaveCalendarRun $run, array $liveIds): void {
		$backend = $run->target->backend;
		try {
			$objects = (method_exists($backend, 'getCalendarObjects') === true) ? $backend->getCalendarObjects($run->target->calendarId) : [];
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveCalendarService: kon kalenderobjecten niet ophalen voor reconciliatie: ' . $e->getMessage());
			return;
		}

		foreach ((is_array($objects) === true ? $objects : []) as $object) {
			$objectUri = (string)(is_array($object) === true ? ($object['uri'] ?? '') : '');
			[$type, $sourceId] = $this->parseHrmqUri($objectUri);
			if ($type === null || $sourceId === null) {
				// Not an humaniq-managed event (manually created by a user); never touched.
				continue;
			}

			if (in_array($sourceId, $liveIds[$type], true) === true) {
				continue;
			}

			$this->deleteOrphan($run, new LeaveCalendarEventRef(type: $type, sourceId: $sourceId));
		}//end foreach

	}//end reconcileOrphans()

	/**
	 * Delete the event of a source that is no longer in the register.
	 *
	 * @param LeaveCalendarRun $run The sync run.
	 * @param LeaveCalendarEventRef $ref The orphaned event.
	 *
	 * @return void
	 */
	private function deleteOrphan(LeaveCalendarRun $run, LeaveCalendarEventRef $ref): void {
		$error = $this->deleteObject($run->target, $ref->objectUri());
		if ($error !== null) {
			$run->record($this->outcome($ref->type, $ref->sourceId, 'failed', 'Verwijderen weeskalenderobject mislukt: ' . $error));
			return;
		}

		$run->record($this->outcome($ref->type, $ref->sourceId, 'removed', 'Weeskalenderobject verwijderd (bron niet meer aanwezig in het register).'));

	}//end deleteOrphan()

	/**
	 * Parse an humaniq-managed object URI (`humaniq-leave-{uuid}.ics` /
	 * `humaniq-sick-{uuid}.ics`) into its source type and id, or
	 * `[null, null]` when the URI carries no humaniq prefix.
	 *
	 * @param string $objectUri The calendar object's URI.
	 *
	 * @return array{0: string|null, 1: string|null}
	 */
	private function parseHrmqUri(string $objectUri): array {
		if (str_starts_with($objectUri, self::LEAVE_URI_PREFIX) === true && str_ends_with($objectUri, '.ics') === true) {
			$id = substr($objectUri, strlen(self::LEAVE_URI_PREFIX), -4);
			return ['leave', $id];
		}

		if (str_starts_with($objectUri, self::SICK_URI_PREFIX) === true && str_ends_with($objectUri, '.ics') === true) {
			$id = substr($objectUri, strlen(self::SICK_URI_PREFIX), -4);
			return ['sick', $id];
		}

		return [null, null];
	}//end parseHrmqUri()

	/**
	 * Fetch a calendar object's raw ICS text, or null on any resolution
	 * failure (design.md D2 diffing input).
	 *
	 * @param CalendarTarget $target The calendar target.
	 * @param string $objectUri The deterministic object URI.
	 *
	 * @return string|null
	 */
	private function fetchCalendarData(CalendarTarget $target, string $objectUri): ?string {
		try {
			if (method_exists($target->backend, 'getCalendarObject') === false) {
				return null;
			}

			$object = $target->backend->getCalendarObject($target->calendarId, $objectUri);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($object) === false || isset($object['calendardata']) === false) {
			return null;
		}

		$data = $object['calendardata'];
		return is_string($data) === true ? $data : null;
	}//end fetchCalendarData()

	/**
	 * Whether a stored VEVENT's DTSTART/DTEND/SUMMARY already match the
	 * desired values (design.md D2 — avoids etag churn on a no-op sync).
	 * DTSTAMP/SEQUENCE are deliberately excluded from the comparison since
	 * DTSTAMP is regenerated on every render.
	 *
	 * @param string $ics The stored calendar object's raw ICS text.
	 * @param LeaveCalendarEvent $event The desired event.
	 *
	 * @return bool
	 */
	private function veventUnchanged(string $ics, LeaveCalendarEvent $event): bool {
		$existingDtstart = $this->extractIcsValue($ics, 'DTSTART');
		$existingDtend = $this->extractIcsValue($ics, 'DTEND');
		$existingSummary = $this->extractIcsValue($ics, 'SUMMARY');

		if ($existingDtstart === null || $existingDtend === null || $existingSummary === null) {
			return false;
		}

		return $existingDtstart === $event->dtstart
			&& $existingDtend === $event->dtendExclusive
			&& $this->unescapeText($existingSummary) === $event->summary;

	}//end veventUnchanged()

	/**
	 * Extract a single property's value from a raw ICS text
	 * (`PROPERTY[;PARAMS]:VALUE`), tolerant of `;VALUE=DATE` parameters and
	 * both CRLF and LF line endings. Returns null when the property is
	 * absent.
	 *
	 * @param string $ics The raw ICS text.
	 * @param string $property The property name (e.g. `DTSTART`).
	 *
	 * @return string|null
	 */
	private function extractIcsValue(string $ics, string $property): ?string {
		$lines = preg_split('/\r\n|\n|\r/', $ics) ?: [];
		foreach ($lines as $line) {
			if (str_starts_with($line, $property . ':') === true) {
				return substr($line, strlen($property) + 1);
			}

			if (str_starts_with($line, $property . ';') === true) {
				$colonPos = strpos($line, ':');
				if ($colonPos !== false) {
					return substr($line, $colonPos + 1);
				}
			}
		}

		return null;
	}//end extractIcsValue()

	/**
	 * Render one all-day VEVENT wrapped in a VCALENDAR (design.md D3): hand-built
	 * RFC 5545, `DTSTART;VALUE=DATE`/`DTEND;VALUE=DATE`, `DTSTAMP` (current
	 * UTC time), `SEQUENCE:0`, `TRANSP:TRANSPARENT`, no ATTENDEE/ORGANIZER/
	 * DESCRIPTION. `SUMMARY` is escaped per RFC 5545 §3.3.11.
	 *
	 * @param LeaveCalendarEvent $event The desired event.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-004
	 */
	private function renderIcs(LeaveCalendarEvent $event): string {
		$dtstamp = gmdate('Ymd\THis\Z');

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//humaniq leave-calendar-nc//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $event->ref->uid(),
			'DTSTAMP:' . $dtstamp,
			'DTSTART;VALUE=DATE:' . $event->dtstart,
			'DTEND;VALUE=DATE:' . $event->dtendExclusive,
			'SUMMARY:' . $this->escapeText($event->summary),
			'TRANSP:TRANSPARENT',
			'SEQUENCE:0',
			'END:VEVENT',
			'END:VCALENDAR',
		];

		return implode("\r\n", $lines) . "\r\n";
	}//end renderIcs()

	/**
	 * Escape a TEXT property value per RFC 5545 §3.3.11 (backslash,
	 * semicolon, comma, newline) — employee names are data, not trusted
	 * literal text.
	 *
	 * @param string $value The raw text.
	 *
	 * @return string
	 */
	private function escapeText(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace([';', ',', "\n"], ['\\;', '\\,', '\\n'], $value);
		return $value;
	}//end escapeText()

	/**
	 * Reverse `escapeText()`, for comparing a stored SUMMARY back to the
	 * desired unescaped value in `veventUnchanged()`.
	 *
	 * @param string $value The escaped text.
	 *
	 * @return string
	 */
	private function unescapeText(string $value): string {
		$value = str_replace(['\\n', '\\,', '\\;'], ["\n", ',', ';'], $value);
		$value = str_replace('\\\\', '\\', $value);
		return $value;
	}//end unescapeText()

	/**
	 * Parse a `Y-m-d` date strictly: a value that only parses by rolling
	 * over (`2026-02-31`, `2026-13-40`) is not a date.
	 *
	 * @param string $date The ISO date (Y-m-d).
	 *
	 * @return \DateTimeImmutable|null
	 */
	private function parseDate(string $date): ?\DateTimeImmutable {
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			return null;
		}

		return $parsed;
	}//end parseDate()

	/**
	 * An `Y-m-d` date, compacted to the RFC 5545 all-day DATE form
	 * (`YYYYMMDD`), or null when unparseable.
	 *
	 * @param string $date The ISO date (Y-m-d).
	 *
	 * @return string|null
	 */
	private function compactDate(string $date): ?string {
		$parsed = $this->parseDate($date);
		if ($parsed === null) {
			return null;
		}

		return $parsed->format('Ymd');
	}//end compactDate()

	/**
	 * An `Y-m-d` date, `$days` later, or the original string when
	 * unparseable (the caller's `compactDate()` call then fails closed).
	 *
	 * @param string $date The ISO date (Y-m-d).
	 * @param int $days The number of days to add.
	 *
	 * @return string
	 */
	private function addDays(string $date, int $days): string {
		$parsed = $this->parseDate($date);
		if ($parsed === null) {
			return $date;
		}

		return $parsed->modify(sprintf('%+d days', $days))->format('Y-m-d');
	}//end addDays()

	/**
	 * Whether `$date` is on or after `$bound` (both `Y-m-d`); unparseable
	 * dates are treated as "in scope" so a malformed `--from` never silently
	 * drops sources (upstream schema validation guards the shape).
	 *
	 * @param string $date The date to check (Y-m-d).
	 * @param string $bound The lower bound (Y-m-d).
	 *
	 * @return bool
	 */
	private function dateOnOrAfter(string $date, string $bound): bool {
		$parsedDate = $this->parseDate($date);
		$parsedBound = $this->parseDate($bound);
		if ($parsedDate === null || $parsedBound === null) {
			return true;
		}

		return $parsedDate >= $parsedBound;
	}//end dateOnOrAfter()

	/**
	 * Build the id/name-keyed Employee index (the `buildRelatedContext()`
	 * idiom), consumed by `employeeName()` for the AVG-safe SUMMARY
	 * (design.md D5).
	 *
	 * @return array<string, array<string, string>>|null Null when the Employee register could not be read.
	 */
	private function buildEmployeeIndex(): ?array {
		$employees = $this->tryLoadAll(self::EMPLOYEE_SCHEMA);
		if ($employees === null) {
			$this->logger->warning('LeaveCalendarService: medewerkersnamen niet beschikbaar; kalenderafspraken worden niet aangemaakt of bijgewerkt tot de volgende sync.');
			return null;
		}

		$byId = [];
		foreach ($employees as $employee) {
			$id = (string)($employee['id'] ?? $employee['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$byId[$id] = [
				'firstName' => (string)($employee['firstName'] ?? ''),
				'lastName' => (string)($employee['lastName'] ?? ''),
			];
		}

		return $byId;
	}//end buildEmployeeIndex()

	/**
	 * Resolve an employee's display name for the SUMMARY, falling back to
	 * the role word `medewerker` rather than leaking a raw id/slug on the
	 * shared calendar (design.md D5).
	 *
	 * @param array<string, array<string, string>>|null $employeesById The Employee index, or null when unavailable.
	 * @param string $employeeId The LeaveRequest/SickLeaveCase employeeId.
	 *
	 * @return string
	 */
	private function employeeName(?array $employeesById, string $employeeId): string {
		$employee = ($employeesById[$employeeId] ?? null);
		if ($employee === null) {
			return 'medewerker';
		}

		$name = trim($employee['firstName'] . ' ' . $employee['lastName']);
		return $name === '' ? 'medewerker' : $name;
	}//end employeeName()

	/**
	 * Duck-typed resolution of `OCA\DAV\CalDAV\CalDavBackend` from the
	 * container (design.md D1), guarded so a resolution failure or a
	 * missing method on any of the five methods this service calls degrades
	 * to null rather than a fatal.
	 *
	 * @return mixed The resolved backend, or null when unavailable.
	 */
	private function resolveBackend(): mixed {
		try {
			$backend = $this->container->get(self::CALDAV_BACKEND_CLASS);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_object($backend) === false) {
			return null;
		}

		$requiredMethods = [
			'getCalendarByUri',
			'getCalendarObjectByUID',
			'createCalendarObject',
			'updateCalendarObject',
			'deleteCalendarObject',
			'getCalendarObjects',
		];
		foreach ($requiredMethods as $method) {
			if (method_exists($backend, $method) === false) {
				// REQ-LC-006: a resolution miss degrades to skipped-no-calendar and
				// SHALL log nothing above INFO -- this is an expected, healthy
				// degradation path, not an error.
				$this->logger->info('LeaveCalendarService: CalDavBackend mist methode ' . $method . '; kalendersync overgeslagen.');
				return null;
			}
		}

		return $backend;
	}//end resolveBackend()

	/**
	 * The per-run availability check (design.md D1): the configured
	 * calendar must resolve on the configured principal.
	 *
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param string $principal The configured CalDAV principal.
	 * @param string $uri The configured calendar URI.
	 *
	 * @return array<string, mixed>|null The calendar row (with an `id` key), or null.
	 */
	private function probeCalendar(mixed $backend, string $principal, string $uri): ?array {
		try {
			$calendar = $backend->getCalendarByUri($principal, $uri);
		} catch (\Throwable $e) {
			return null;
		}

		return is_array($calendar) === true ? $calendar : null;
	}//end probeCalendar()

	/**
	 * Build the `skipped-no-calendar` outcome row that ends a run cleanly
	 * (design.md D1/D6, REQ-LC-006) — the exit-0 path.
	 *
	 * @param string $message The human-readable reason.
	 *
	 * @return array<string, mixed>
	 */
	private function skipOutcome(string $message): array {
		return $this->outcome(null, null, 'skipped-no-calendar', $message);
	}//end skipOutcome()

	/**
	 * Build one outcome row.
	 *
	 * @param string|null $type `leave`, `sick`, or null for a run-level outcome.
	 * @param string|null $sourceId The source object id, or null for a run-level outcome.
	 * @param string $status `created|updated|removed|unchanged|failed|skipped|skipped-no-calendar`.
	 * @param string $message A human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(?string $type, ?string $sourceId, string $status, string $message): array {
		return [
			'type' => $type,
			'sourceId' => $sourceId,
			'status' => $status,
			'message' => $message,
		];

	}//end outcome()

	/**
	 * Load and normalise every object of a schema via OpenRegister's
	 * ObjectService (the `RuleAuditService::loadAll()` idiom), or null
	 * (logged) when the register cannot be read.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function tryLoadAll(string $schema): ?array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveCalendarService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return null;
		}

		return $this->normaliseRows($rows);
	}//end tryLoadAll()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end normaliseRows()

	/**
	 * Normalise a single ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
