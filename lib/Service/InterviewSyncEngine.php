<?php

/**
 * Interview Sync Engine
 *
 * Per-Interview sync decisions for interview-scheduling, extracted out of
 * `InterviewCalendarService` (single-responsibility split so the parent
 * service stays a thin orchestrator): status dispatch (`scheduled` upserts,
 * `cancelled` removes, `completed` is left untouched, design.md D3/D4) and
 * the idempotent create-vs-update-vs-unchanged decision against the
 * duck-typed CalDavBackend (via the injected `InterviewCalendarTarget`).
 * Orphan reconciliation is `InterviewOrphanReconciler`'s own responsibility.
 * Delegates ICS rendering/diffing to `InterviewIcsRenderer` and Interview/
 * Application data access + the PUT-semantic `calendarEventUid` persist to
 * `InterviewRepository`.
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
 * @spec openspec/specs/interview-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use RuntimeException;
use Throwable;

/**
 * Per-Interview status dispatch, idempotent upsert/remove, and the
 * duck-typed CalDavBackend I/O.
 *
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
 */
class InterviewSyncEngine {

	/**
	 * @var string
	 */
	// FROZEN at the old app id: already-synced CalDAV events carry this URI
	// prefix. Renaming it would double-create every interview event and leave
	// the existing ones permanently unreclaimable by the orphan reconciler.
	private const URI_PREFIX = 'hrmq-interview-';

	/**
	 * The statuses a sync leaves alone, with the outcome message. design.md
	 * D4: a completed Interview's last-synced event stays on the calendar as
	 * completed history -- no further sync touches it.
	 *
	 * @var array<string, string>
	 */
	private const LEFT_ALONE = [
		'completed' => 'Interview is voltooid; kalenderafspraak blijft ongewijzigd als geschiedenis.',
	];

	/**
	 * @param InterviewRepository $repository Interview/Application/Vacancy data access + PUT-semantic persist.
	 * @param InterviewIcsRenderer $icsRenderer ICS render/diff/date-parse helper.
	 * @param InterviewAttendees|null $attendees  Names the organiser and the interviewers as attendees; without it the event carries the interviewers as text only.
	 */
	public function __construct(
		private readonly InterviewRepository $repository,
		private readonly InterviewIcsRenderer $icsRenderer,
		private readonly ?InterviewAttendees $attendees = null,
	) {

	}//end __construct()

	/**
	 * Dispatch one Interview by status (design.md D3/D4): `scheduled`
	 * upserts (bounded by `$from` on scheduledStart), `cancelled` removes
	 * the event, `completed` is left untouched (kept as history).
	 *
	 * @param InterviewSyncRun $run The target, indexes and bound of the run, collecting the outcomes.
	 * @param array<string, mixed> $interview The Interview object.
	 * @param string $id The Interview id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 */
	public function syncInterview(InterviewSyncRun $run, array $interview, string $id): void {
		$sync = new InterviewEventSync();
		$sync->interview = $interview;
		$sync->id = $id;
		$status = (string)($interview['status'] ?? '');
		if ($status === 'cancelled') {
			$this->removeInterviewEvent(run: $run, sync: $sync);
			return;
		}

		if ($status === 'scheduled') {
			$this->syncScheduledInterview(run: $run, sync: $sync);
			return;
		}

		$run->results[] = $this->outcome($id, 'unchanged', (self::LEFT_ALONE[$status] ?? 'Onbekende status; overgeslagen.'));

	}//end syncInterview()

	/**
	 * Validate/bound/render one `scheduled` Interview, then upsert it.
	 *
	 * @param InterviewSyncRun   $run  The target, indexes and bound of the run, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	private function syncScheduledInterview(InterviewSyncRun $run, InterviewEventSync $sync): void {
		if ($this->scheduleIsUsable(run: $run, sync: $sync) === false) {
			return;
		}

		$sync->prepared = $this->prepareEvent(run: $run, sync: $sync);
		$this->upsertInterviewEvent(run: $run, sync: $sync);

	}//end syncScheduledInterview()

	/**
	 * Whether the Interview has a start and end, inside the run's bound, that
	 * read as date-times. A missing or unreadable one appends a `failed`
	 * outcome; one before the bound is left alone without an outcome.
	 *
	 * @param InterviewSyncRun   $run  The run: its bound, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced; its compact start and end are set.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	private function scheduleIsUsable(InterviewSyncRun $run, InterviewEventSync $sync): bool {
		$scheduledStart = trim((string)($sync->interview['scheduledStart'] ?? ''));
		$scheduledEnd = trim((string)($sync->interview['scheduledEnd'] ?? ''));
		if ($scheduledStart === '' || $scheduledEnd === '') {
			$run->results[] = $this->outcome($sync->id, 'failed', 'Interview mist scheduledStart/scheduledEnd; kan niet gesynchroniseerd worden.');
			return false;
		}

		if ($run->from !== null && $this->icsRenderer->scheduledStartOnOrAfter($scheduledStart, $run->from) === false) {
			// Out of the --from bound; an already-synced event stays untouched.
			return false;
		}

		return $this->readTimes(run: $run, sync: $sync);
	}//end scheduleIsUsable()

	/**
	 * Read the Interview's start and end as compact date-times, or append a
	 * `failed` outcome when either does not read.
	 *
	 * @param InterviewSyncRun   $run  The run, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced; its compact start and end are set.
	 *
	 * @return bool
	 */
	private function readTimes(InterviewSyncRun $run, InterviewEventSync $sync): bool {
		$sync->dtstart = $this->icsRenderer->compactDateTime(trim((string)($sync->interview['scheduledStart'] ?? '')));
		$sync->dtend = $this->icsRenderer->compactDateTime(trim((string)($sync->interview['scheduledEnd'] ?? '')));
		if ($sync->dtstart === null || $sync->dtend === null) {
			$run->results[] = $this->outcome($sync->id, 'failed', 'Interview scheduledStart/scheduledEnd is geen geldige datum/tijd.');
			return false;
		}

		return true;
	}//end readTimes()

	/**
	 * Render the Interview's event under its stored UID, or under the
	 * deterministic one when none is stored yet.
	 *
	 * @param InterviewSyncRun   $run  The run: its application and vacancy indexes.
	 * @param InterviewEventSync $sync The Interview being synced, its start and end read.
	 *
	 * @return PreparedInterviewEvent
	 */
	private function prepareEvent(InterviewSyncRun $run, InterviewEventSync $sync): PreparedInterviewEvent {
		$interview = $sync->interview;
		$applicationId = (string)($interview['applicationId'] ?? '');
		$summary = $this->repository->resolveSummary($run->applicationsById, $run->vacanciesById, $applicationId);
		$location = trim((string)($interview['location'] ?? ''));
		$storedUid = trim((string)($interview['calendarEventUid'] ?? ''));
		$uid = ($storedUid !== '') ? $storedUid : (self::URI_PREFIX . $sync->id);
		$event = ['dtstart' => $sync->dtstart, 'dtend' => $sync->dtend, 'summary' => $summary, 'location' => $location];
		$attendees = null;
		if ($this->attendees === null) {
			$event['interviewers'] = $this->interviewersText(interviewers: ($interview['interviewers'] ?? ''));
		} else {
			// REQ-INTV-010: the interviewers are attendees, the calendar's owner organises.
			$people = $this->attendees->forEvent(interviewers: ($interview['interviewers'] ?? null), principal: $run->target->principal);
			$event['interviewers'] = $people['description'];
			$event['organizer'] = $people['organizer'];
			$event['attendees'] = $people['attendees'];
			$attendees = $people['attendees'];
		}

		$ics = $this->icsRenderer->render($uid, $event);
		return new PreparedInterviewEvent($storedUid, $uid, $uid . '.ics', $ics, (string)$sync->dtstart, (string)$sync->dtend, $summary, $location, $attendees);
	}//end prepareEvent()

	/**
	 * The interviewers as description text, for an engine that names no attendees.
	 *
	 * @param mixed $interviewers The stored value: user ids, or text stored before they were.
	 *
	 * @return string
	 */
	private function interviewersText(mixed $interviewers): string {
		if (is_array($interviewers) === true) {
			return implode(', ', InterviewAttendees::ids(interviewers: $interviewers));
		}

		return trim((string)$interviewers);
	}//end interviewersText()

	/**
	 * Create or update one VEVENT, identified by the prepared UID
	 * (design.md D3): probe `getCalendarObjectByUID`; absent ⇒ create;
	 * present ⇒ diff and update only when changed.
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced, its event prepared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	private function upsertInterviewEvent(InterviewSyncRun $run, InterviewEventSync $sync): void {
		[$probeSucceeded, $existingPath] = $this->probeExistingPath($run, $sync->prepared->uid, $sync->id);
		if ($probeSucceeded === false) {
			return;
		}

		if ($existingPath === null) {
			$this->createEvent(run: $run, sync: $sync);
			return;
		}

		$this->updateEventIfChanged(run: $run, sync: $sync);

	}//end upsertInterviewEvent()

	/**
	 * Probe `getCalendarObjectByUID` for the given UID, scoped to the
	 * configured calendar. Returns `[true, $path]` on success (`$path` null
	 * means not found) or `[false, null]` after appending a `failed`
	 * outcome.
	 *
	 * @param InterviewSyncRun $run The run: its target, collecting the outcomes.
	 * @param string $uid The VEVENT UID to look up.
	 * @param string $id The Interview id, for the outcome row.
	 *
	 * @return array{0: bool, 1: mixed}
	 */
	private function probeExistingPath(InterviewSyncRun $run, string $uid, string $id): array {
		try {
			$path = (method_exists($run->target->backend, 'getCalendarObjectByUID') === true)
				? $run->target->backend->getCalendarObjectByUID($run->target->principal, $uid, $run->target->calendarUri)
				: null;
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($id, 'failed', 'Kon bestaand kalenderobject niet opzoeken: ' . $e->getMessage());
			return [false, null];
		}

		return [true, $path];
	}//end probeExistingPath()

	/**
	 * Create the calendar object and, unless the UID was already stored,
	 * persist it back onto the Interview (design.md D3).
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced, its event prepared.
	 *
	 * @return void
	 */
	private function createEvent(InterviewSyncRun $run, InterviewEventSync $sync): void {
		try {
			if (method_exists($run->target->backend, 'createCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::createCalendarObject is not available.');
			}

			$run->target->backend->createCalendarObject($run->target->calendarId, $sync->prepared->objectUri, $sync->prepared->ics);
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($sync->id, 'failed', 'Aanmaken kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		$this->rememberUid(sync: $sync);
		$run->results[] = $this->outcome($sync->id, 'created', 'Kalenderafspraak aangemaakt.');

	}//end createEvent()

	/**
	 * Diff the existing stored event against the desired values and update
	 * only when they differ (avoids etag churn on a no-op sync); persists
	 * the UID back onto the Interview if it was not already stored (the
	 * defensive self-heal path for a prior sync that created the event but
	 * crashed before persisting).
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced, its event prepared.
	 *
	 * @return void
	 */
	private function updateEventIfChanged(InterviewSyncRun $run, InterviewEventSync $sync): void {
		$prepared = $sync->prepared;
		$existingIcs = $this->fetchCalendarData($run->target, $prepared->objectUri);
		$wanted = ['dtstart' => $prepared->dtstart, 'dtend' => $prepared->dtend, 'summary' => $prepared->summary, 'location' => $prepared->location];
		if ($prepared->attendees !== null) {
			$wanted['attendees'] = $prepared->attendees;
		}

		if ($existingIcs !== null && $this->icsRenderer->unchanged($existingIcs, $wanted) === true) {
			$this->rememberUid(sync: $sync);
			$run->results[] = $this->outcome($sync->id, 'unchanged', 'Geen wijziging.');
			return;
		}

		if ($this->writeUpdate(run: $run, sync: $sync) === false) {
			return;
		}

		$this->rememberUid(sync: $sync);
		$run->results[] = $this->outcome($sync->id, 'updated', 'Kalenderafspraak bijgewerkt.');

	}//end updateEventIfChanged()

	/**
	 * Write the prepared event over the existing calendar object, or append
	 * a `failed` outcome when that fails.
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced, its event prepared.
	 *
	 * @return bool Whether the object was updated.
	 */
	private function writeUpdate(InterviewSyncRun $run, InterviewEventSync $sync): bool {
		try {
			if (method_exists($run->target->backend, 'updateCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::updateCalendarObject is not available.');
			}

			$run->target->backend->updateCalendarObject($run->target->calendarId, $sync->prepared->objectUri, $sync->prepared->ics);
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($sync->id, 'failed', 'Bijwerken kalenderafspraak mislukt: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end writeUpdate()

	/**
	 * Persist the event's UID onto the Interview when it was not stored yet.
	 *
	 * @param InterviewEventSync $sync The Interview being synced, its event prepared.
	 *
	 * @return void
	 */
	private function rememberUid(InterviewEventSync $sync): void {
		if ($sync->prepared->storedUid === '') {
			$this->repository->persistCalendarEventUid($sync->interview, $sync->id, $sync->prepared->uid);
		}
	}//end rememberUid()

	/**
	 * Delete the event of a cancelled Interview (design.md D4), looked up
	 * by the Interview's stored `calendarEventUid` -- a no-op (`unchanged`)
	 * when no UID is stored yet or no event is found under it. The
	 * Interview's own `calendarEventUid` field is left as-is (a historical
	 * pointer; humaniq's own audit log is the record of what happened, not the
	 * calendar).
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 */
	private function removeInterviewEvent(InterviewSyncRun $run, InterviewEventSync $sync): void {
		$storedUid = trim((string)($sync->interview['calendarEventUid'] ?? ''));
		if ($storedUid === '') {
			$run->results[] = $this->outcome($sync->id, 'unchanged', 'Geen kalenderafspraak om te verwijderen.');
			return;
		}

		$this->removeStoredEvent(run: $run, sync: $sync);

	}//end removeInterviewEvent()

	/**
	 * Delete the event under the Interview's stored UID when the calendar
	 * still holds it.
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced, with a stored UID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 */
	private function removeStoredEvent(InterviewSyncRun $run, InterviewEventSync $sync): void {
		[$probeSucceeded, $existingPath] = $this->probeExistingPath($run, trim((string)$sync->interview['calendarEventUid']), $sync->id);
		if ($probeSucceeded === false) {
			return;
		}

		if ($existingPath === null) {
			$run->results[] = $this->outcome($sync->id, 'unchanged', 'Geen kalenderafspraak (meer) aanwezig.');
			return;
		}

		$this->deleteEvent(run: $run, sync: $sync);
	}//end removeStoredEvent()

	/**
	 * Delete the calendar object under the Interview's stored UID.
	 *
	 * @param InterviewSyncRun   $run  The run: its target, collecting the outcomes.
	 * @param InterviewEventSync $sync The Interview being synced, with a stored UID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 */
	private function deleteEvent(InterviewSyncRun $run, InterviewEventSync $sync): void {
		try {
			if (method_exists($run->target->backend, 'deleteCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
			}

			$run->target->backend->deleteCalendarObject($run->target->calendarId, trim((string)$sync->interview['calendarEventUid']) . '.ics');
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($sync->id, 'failed', 'Verwijderen kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		$run->results[] = $this->outcome($sync->id, 'removed', 'Kalenderafspraak verwijderd.');
	}//end deleteEvent()

	/**
	 * Fetch a calendar object's raw ICS text, or null on any resolution
	 * failure (design.md D3 diffing input).
	 *
	 * @param InterviewCalendarTarget $target The resolved sync target.
	 * @param string $objectUri The deterministic object URI.
	 *
	 * @return string|null
	 */
	private function fetchCalendarData(InterviewCalendarTarget $target, string $objectUri): ?string {
		try {
			if (method_exists($target->backend, 'getCalendarObject') === false) {
				return null;
			}

			$object = $target->backend->getCalendarObject($target->calendarId, $objectUri);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($object) === false || isset($object['calendardata']) === false) {
			return null;
		}

		$data = $object['calendardata'];
		return is_string($data) === true ? $data : null;
	}//end fetchCalendarData()

	/**
	 * Build one outcome row.
	 *
	 * @param string $sourceId The Interview id.
	 * @param string $status `created|updated|removed|unchanged|failed`.
	 * @param string $message A human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $sourceId, string $status, string $message): array {
		return [
			'type' => 'interview',
			'sourceId' => $sourceId,
			'status' => $status,
			'message' => $message,
		];

	}//end outcome()

}//end class
