<?php

/**
 * Leave Calendar Service
 *
 * Projects approved absence (LeaveRequest/SickLeaveCase) onto one configured
 * shared Nextcloud calendar as all-day VEVENTs (design.md D1-D6). hrmq holds
 * no calendar storage of its own — the sync is a stateless, idempotent
 * upsert/remove pass driven on operator demand (occ hrmq:calendar:sync), not
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
 * the log — and hrmq gains no info.xml/composer dependency on the calendar
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
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Upserts/removes approved-absence VEVENTs on the configured shared calendar.
 */
class LeaveCalendarService {

	/**
	 * The duck-typed CalDAV backend's FQCN, resolved from the container by
	 * string (design.md D1) — never a `use` import, so hrmq carries no
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
	private const LEAVE_URI_PREFIX = 'hrmq-leave-';

	/**
	 * Object-URI/UID prefix for SickLeaveCase-derived events (design.md D2).
	 *
	 * @var string
	 */
	private const SICK_URI_PREFIX = 'hrmq-sick-';

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
	 * scope, and reconcile orphaned `hrmq-`-prefixed events whose source was
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

		$calendarId = $calendar['id'];

		$employeesById = $this->buildEmployeeIndex();

		$results = [];
		$liveLeaveIds = [];
		$liveSickIds = [];

		foreach ($this->loadAll(self::LEAVE_SCHEMA) as $leave) {
			$id = (string)($leave['id'] ?? $leave['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$liveLeaveIds[] = $id;
			$this->syncLeave($backend, $calendarId, $principal, $uri, $leave, $id, $employeesById, $from, $results);
		}

		foreach ($this->loadAll(self::SICK_SCHEMA) as $sick) {
			$id = (string)($sick['id'] ?? $sick['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$liveSickIds[] = $id;
			$this->syncSick($backend, $calendarId, $principal, $uri, $sick, $id, $employeesById, $from, $results);
		}

		$this->reconcileOrphans($backend, $calendarId, $liveLeaveIds, $liveSickIds, $results);

		return $results;
	}//end sync()

	/**
	 * Upsert or remove the event for one LeaveRequest (design.md D2/D5,
	 * REQ-LC-002/REQ-LC-005): `approved` upserts (bounded by `$from` on
	 * endDate), any other status removes the event if it exists.
	 *
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param string $principal The configured CalDAV principal.
	 * @param string $calendarUri The configured calendar URI.
	 * @param array<string, mixed> $leave The LeaveRequest object.
	 * @param string $id The LeaveRequest id.
	 * @param array<string, array<string, string>> $employeesById Employee name index.
	 * @param string|null $from Optional bound (Y-m-d).
	 * @param array<int, array<string, mixed>> &$results Outcome accumulator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function syncLeave(
		mixed $backend,
		mixed $calendarId,
		string $principal,
		string $calendarUri,
		array $leave,
		string $id,
		array $employeesById,
		?string $from,
		array &$results,
	): void {
		$uid = self::LEAVE_URI_PREFIX . $id;
		$objectUri = $uid . '.ics';
		$status = (string)($leave['status'] ?? '');

		if ($status !== 'approved') {
			$this->removeEvent($backend, $calendarId, $principal, $calendarUri, $uid, $objectUri, 'leave', $id, $results);
			return;
		}

		$startDate = trim((string)($leave['startDate'] ?? ''));
		$endDate = trim((string)($leave['endDate'] ?? ''));
		if ($startDate === '' || $endDate === '') {
			$results[] = $this->outcome('leave', $id, 'failed', 'LeaveRequest mist startDate/endDate; kan niet gesynchroniseerd worden.');
			return;
		}

		if ($from !== null && $this->dateOnOrAfter($endDate, $from) === false) {
			// Out of the --from bound; an already-synced event stays untouched.
			return;
		}

		$dtstart = $this->compactDate($startDate);
		$dtendExclusive = $this->compactDate($this->addDays($endDate, 1));
		if ($dtstart === null || $dtendExclusive === null) {
			$results[] = $this->outcome('leave', $id, 'failed', 'LeaveRequest startDate/endDate is geen geldige datum.');
			return;
		}

		$employeeId = (string)($leave['employeeId'] ?? '');
		$summary = 'Verlof — ' . $this->employeeName($employeesById, $employeeId);

		$this->upsertEvent($backend, $calendarId, $principal, $calendarUri, $uid, $objectUri, $dtstart, $dtendExclusive, $summary, 'leave', $id, $results);

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
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param string $principal The configured CalDAV principal.
	 * @param string $calendarUri The configured calendar URI.
	 * @param array<string, mixed> $sick The SickLeaveCase object.
	 * @param string $id The SickLeaveCase id.
	 * @param array<string, array<string, string>> $employeesById Employee name index.
	 * @param string|null $from Optional bound (Y-m-d), applied to hersteld cases only.
	 * @param array<int, array<string, mixed>> &$results Outcome accumulator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-003
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-004
	 */
	private function syncSick(
		mixed $backend,
		mixed $calendarId,
		string $principal,
		string $calendarUri,
		array $sick,
		string $id,
		array $employeesById,
		?string $from,
		array &$results,
	): void {
		$uid = self::SICK_URI_PREFIX . $id;
		$objectUri = $uid . '.ics';
		$status = (string)($sick['status'] ?? '');

		$firstSickDay = trim((string)($sick['firstSickDay'] ?? ''));
		if ($firstSickDay === '') {
			$results[] = $this->outcome('sick', $id, 'failed', 'SickLeaveCase mist firstSickDay; kan niet gesynchroniseerd worden.');
			return;
		}

		// Default (gemeld / open): rolling bar through today, re-extended every
		// sync, never bounded by --from (design.md D4/Risks). A recovered case
		// overrides this below; addDays() is pure, so computing it up front is
		// equivalent to the former if/else.
		$dtendExclusiveSource = $this->addDays(gmdate('Y-m-d'), 1);

		if ($status === 'hersteld') {
			$recoveredDate = trim((string)($sick['recoveredDate'] ?? ''));
			if ($recoveredDate === '') {
				$results[] = $this->outcome('sick', $id, 'skipped', 'SickLeaveCase is hersteld maar recoveredDate ontbreekt; overgeslagen (geen gok).');
				return;
			}

			if ($from !== null && $this->dateOnOrAfter($recoveredDate, $from) === false) {
				return;
			}

			$dtendExclusiveSource = $this->addDays($recoveredDate, 1);
		}

		$dtstart = $this->compactDate($firstSickDay);
		$dtendExclusive = $this->compactDate((string)$dtendExclusiveSource);
		if ($dtstart === null || $dtendExclusive === null) {
			$results[] = $this->outcome('sick', $id, 'failed', 'SickLeaveCase firstSickDay/recoveredDate is geen geldige datum.');
			return;
		}

		$employeeId = (string)($sick['employeeId'] ?? '');
		$summary = 'Afwezig — ' . $this->employeeName($employeesById, $employeeId);

		$this->upsertEvent($backend, $calendarId, $principal, $calendarUri, $uid, $objectUri, $dtstart, $dtendExclusive, $summary, 'sick', $id, $results);

	}//end syncSick()

	/**
	 * Create or update one VEVENT (design.md D2): probe
	 * `getCalendarObjectByUID` scoped to the configured calendar; absent ⇒
	 * create; present ⇒ diff the rendered DTSTART/DTEND/SUMMARY against the
	 * stored event and update only when they differ (avoids etag churn on a
	 * no-op sync — the idempotency invariant tasks.md/spec require).
	 *
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param string $principal The configured CalDAV principal.
	 * @param string $calendarUri The configured calendar URI.
	 * @param string $uid The deterministic VEVENT UID.
	 * @param string $objectUri The deterministic object URI (`{uid}.ics`).
	 * @param string $dtstart Compact DATE value (YYYYMMDD).
	 * @param string $dtendExclusive Compact DATE value (YYYYMMDD), exclusive.
	 * @param string $summary The AVG-safe SUMMARY text (unescaped).
	 * @param string $type `leave` or `sick`, for the outcome row.
	 * @param string $sourceId The source object id, for the outcome row.
	 * @param array<int, array<string, mixed>> &$results Outcome accumulator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 */
	private function upsertEvent(
		mixed $backend,
		mixed $calendarId,
		string $principal,
		string $calendarUri,
		string $uid,
		string $objectUri,
		string $dtstart,
		string $dtendExclusive,
		string $summary,
		string $type,
		string $sourceId,
		array &$results,
	): void {
		try {
			$existingPath = (method_exists($backend, 'getCalendarObjectByUID') === true)
				? $backend->getCalendarObjectByUID($principal, $uid, $calendarUri)
				: null;
		} catch (\Throwable $e) {
			$results[] = $this->outcome($type, $sourceId, 'failed', 'Kon bestaand kalenderobject niet opzoeken: ' . $e->getMessage());
			return;
		}

		$ics = $this->renderIcs($uid, $dtstart, $dtendExclusive, $summary);

		if ($existingPath === null) {
			try {
				if (method_exists($backend, 'createCalendarObject') === false) {
					throw new RuntimeException('CalDavBackend::createCalendarObject is not available.');
				}

				$backend->createCalendarObject($calendarId, $objectUri, $ics);
			} catch (\Throwable $e) {
				$results[] = $this->outcome($type, $sourceId, 'failed', 'Aanmaken kalenderafspraak mislukt: ' . $e->getMessage());
				return;
			}

			$results[] = $this->outcome($type, $sourceId, 'created', 'Kalenderafspraak aangemaakt.');
			return;
		}//end if

		$existingIcs = $this->fetchCalendarData($backend, $calendarId, $objectUri);
		if ($existingIcs !== null && $this->veventUnchanged($existingIcs, $dtstart, $dtendExclusive, $summary) === true) {
			$results[] = $this->outcome($type, $sourceId, 'unchanged', 'Geen wijziging.');
			return;
		}

		try {
			if (method_exists($backend, 'updateCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::updateCalendarObject is not available.');
			}

			$backend->updateCalendarObject($calendarId, $objectUri, $ics);
		} catch (\Throwable $e) {
			$results[] = $this->outcome($type, $sourceId, 'failed', 'Bijwerken kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		$results[] = $this->outcome($type, $sourceId, 'updated', 'Kalenderafspraak bijgewerkt.');

	}//end upsertEvent()

	/**
	 * Delete the event of a source that left scope (design.md D2,
	 * REQ-LC-005) — a no-op (`unchanged`) when no event exists.
	 *
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param string $principal The configured CalDAV principal.
	 * @param string $calendarUri The configured calendar URI.
	 * @param string $uid The deterministic VEVENT UID.
	 * @param string $objectUri The deterministic object URI (`{uid}.ics`).
	 * @param string $type `leave` or `sick`, for the outcome row.
	 * @param string $sourceId The source object id, for the outcome row.
	 * @param array<int, array<string, mixed>> &$results Outcome accumulator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function removeEvent(
		mixed $backend,
		mixed $calendarId,
		string $principal,
		string $calendarUri,
		string $uid,
		string $objectUri,
		string $type,
		string $sourceId,
		array &$results,
	): void {
		try {
			$existingPath = (method_exists($backend, 'getCalendarObjectByUID') === true)
				? $backend->getCalendarObjectByUID($principal, $uid, $calendarUri)
				: null;
		} catch (\Throwable $e) {
			$results[] = $this->outcome($type, $sourceId, 'failed', 'Kon kalenderobject niet opzoeken voor verwijdering: ' . $e->getMessage());
			return;
		}

		if ($existingPath === null) {
			$results[] = $this->outcome($type, $sourceId, 'unchanged', 'Geen kalenderafspraak om te verwijderen.');
			return;
		}

		try {
			if (method_exists($backend, 'deleteCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
			}

			$backend->deleteCalendarObject($calendarId, $objectUri);
		} catch (\Throwable $e) {
			$results[] = $this->outcome($type, $sourceId, 'failed', 'Verwijderen kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		$results[] = $this->outcome($type, $sourceId, 'removed', 'Kalenderafspraak verwijderd.');

	}//end removeEvent()

	/**
	 * Orphan reconciliation (design.md D2, REQ-LC-005): list the configured
	 * calendar's objects and delete every `hrmq-leave-*.ics` / `hrmq-sick-*.ics`
	 * URI whose embedded uuid is not among the given (full, unbounded) live
	 * id sets. Objects without the `hrmq-` prefix (manually created by users)
	 * are never touched.
	 *
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param array<int, string> $liveLeaveIds All currently-existing LeaveRequest ids.
	 * @param array<int, string> $liveSickIds All currently-existing SickLeaveCase ids.
	 * @param array<int, array<string, mixed>> &$results Outcome accumulator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-005
	 */
	private function reconcileOrphans(mixed $backend, mixed $calendarId, array $liveLeaveIds, array $liveSickIds, array &$results): void {
		try {
			$objects = (method_exists($backend, 'getCalendarObjects') === true) ? $backend->getCalendarObjects($calendarId) : [];
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveCalendarService: kon kalenderobjecten niet ophalen voor reconciliatie: ' . $e->getMessage());
			return;
		}

		foreach ((is_array($objects) === true ? $objects : []) as $object) {
			$objectUri = (string)(is_array($object) === true ? ($object['uri'] ?? '') : '');
			[$type, $sourceId] = $this->parseHrmqUri($objectUri);
			if ($type === null || $sourceId === null) {
				// Not an hrmq-managed event (manually created by a user); never touched.
				continue;
			}

			$liveIds = ($type === 'leave') ? $liveLeaveIds : $liveSickIds;
			if (in_array($sourceId, $liveIds, true) === true) {
				continue;
			}

			try {
				if (method_exists($backend, 'deleteCalendarObject') === false) {
					throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
				}

				$backend->deleteCalendarObject($calendarId, $objectUri);
			} catch (\Throwable $e) {
				$results[] = $this->outcome($type, $sourceId, 'failed', 'Verwijderen weeskalenderobject mislukt: ' . $e->getMessage());
				continue;
			}

			$results[] = $this->outcome($type, $sourceId, 'removed', 'Weeskalenderobject verwijderd (bron niet meer aanwezig in het register).');
		}//end foreach

	}//end reconcileOrphans()

	/**
	 * Parse an hrmq-managed object URI (`hrmq-leave-{uuid}.ics` /
	 * `hrmq-sick-{uuid}.ics`) into its source type and id, or
	 * `[null, null]` when the URI carries no hrmq prefix.
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
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param string $objectUri The deterministic object URI.
	 *
	 * @return string|null
	 */
	private function fetchCalendarData(mixed $backend, mixed $calendarId, string $objectUri): ?string {
		try {
			if (method_exists($backend, 'getCalendarObject') === false) {
				return null;
			}

			$object = $backend->getCalendarObject($calendarId, $objectUri);
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
	 * @param string $dtstart Desired compact DATE value (YYYYMMDD).
	 * @param string $dtendExclusive Desired compact DATE value (YYYYMMDD), exclusive.
	 * @param string $summary Desired SUMMARY text (unescaped).
	 *
	 * @return bool
	 */
	private function veventUnchanged(string $ics, string $dtstart, string $dtendExclusive, string $summary): bool {
		$existingDtstart = $this->extractIcsValue($ics, 'DTSTART');
		$existingDtend = $this->extractIcsValue($ics, 'DTEND');
		$existingSummary = $this->extractIcsValue($ics, 'SUMMARY');

		if ($existingDtstart === null || $existingDtend === null || $existingSummary === null) {
			return false;
		}

		return $existingDtstart === $dtstart
			&& $existingDtend === $dtendExclusive
			&& $this->unescapeText($existingSummary) === $summary;

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
	 * @param string $uid The deterministic VEVENT UID.
	 * @param string $dtstart Compact DATE value (YYYYMMDD).
	 * @param string $dtendExclusive Compact DATE value (YYYYMMDD), exclusive.
	 * @param string $summary The AVG-safe SUMMARY text (unescaped).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-004
	 */
	private function renderIcs(string $uid, string $dtstart, string $dtendExclusive, string $summary): string {
		$dtstamp = gmdate('Ymd\THis\Z');

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//hrmq leave-calendar-nc//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART;VALUE=DATE:' . $dtstart,
			'DTEND;VALUE=DATE:' . $dtendExclusive,
			'SUMMARY:' . $this->escapeText($summary),
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
	 * An `Y-m-d` date, compacted to the RFC 5545 all-day DATE form
	 * (`YYYYMMDD`), or null when unparseable.
	 *
	 * @param string $date The ISO date (Y-m-d).
	 *
	 * @return string|null
	 */
	private function compactDate(string $date): ?string {
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false) {
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
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false) {
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
		$parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		$parsedBound = \DateTimeImmutable::createFromFormat('!Y-m-d', $bound);
		if ($parsedDate === false || $parsedBound === false) {
			return true;
		}

		return $parsedDate >= $parsedBound;
	}//end dateOnOrAfter()

	/**
	 * Build the id/name-keyed Employee index (the `buildRelatedContext()`
	 * idiom), consumed by `employeeName()` for the AVG-safe SUMMARY
	 * (design.md D5).
	 *
	 * @return array<string, array<string, string>>
	 */
	private function buildEmployeeIndex(): array {
		$byId = [];
		foreach ($this->loadAll(self::EMPLOYEE_SCHEMA) as $employee) {
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
	 * @param array<string, array<string, string>> $employeesById The Employee index.
	 * @param string $employeeId The LeaveRequest/SickLeaveCase employeeId.
	 *
	 * @return string
	 */
	private function employeeName(array $employeesById, string $employeeId): string {
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
	 * ObjectService (the `RuleAuditService::loadAll()` idiom), degrading to
	 * an empty list on any resolution failure.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('LeaveCalendarService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

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
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
