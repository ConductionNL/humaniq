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
	 * @param InterviewRepository $repository Interview/Application/Vacancy data access + PUT-semantic persist.
	 * @param InterviewIcsRenderer $icsRenderer ICS render/diff/date-parse helper.
	 */
	public function __construct(
		private readonly InterviewRepository $repository,
		private readonly InterviewIcsRenderer $icsRenderer,
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
		$status = (string)($interview['status'] ?? '');

		if ($status === 'cancelled') {
			$this->removeInterviewEvent($run, $interview, $id);
			return;
		}

		if ($status === 'completed') {
			// design.md D4: a completed Interview's last-synced event stays
			// on the calendar as completed history -- no further sync
			// touches it.
			$run->results[] = $this->outcome($id, 'unchanged', 'Interview is voltooid; kalenderafspraak blijft ongewijzigd als geschiedenis.');
			return;
		}

		if ($status !== 'scheduled') {
			$run->results[] = $this->outcome($id, 'unchanged', 'Onbekende status; overgeslagen.');
			return;
		}

		$this->syncScheduledInterview($run, $interview, $id);

	}//end syncInterview()

	/**
	 * Validate/bound/render one `scheduled` Interview, then upsert it.
	 *
	 * @param InterviewSyncRun $run The target, indexes and bound of the run, collecting the outcomes.
	 * @param array<string, mixed> $interview The Interview object.
	 * @param string $id The Interview id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	private function syncScheduledInterview(InterviewSyncRun $run, array $interview, string $id): void {
		$scheduledStart = trim((string)($interview['scheduledStart'] ?? ''));
		$scheduledEnd = trim((string)($interview['scheduledEnd'] ?? ''));
		if ($scheduledStart === '' || $scheduledEnd === '') {
			$run->results[] = $this->outcome($id, 'failed', 'Interview mist scheduledStart/scheduledEnd; kan niet gesynchroniseerd worden.');
			return;
		}

		if ($run->from !== null && $this->icsRenderer->scheduledStartOnOrAfter($scheduledStart, $run->from) === false) {
			// Out of the --from bound; an already-synced event stays untouched.
			return;
		}

		$dtstart = $this->icsRenderer->compactDateTime($scheduledStart);
		$dtend = $this->icsRenderer->compactDateTime($scheduledEnd);
		if ($dtstart === null || $dtend === null) {
			$run->results[] = $this->outcome($id, 'failed', 'Interview scheduledStart/scheduledEnd is geen geldige datum/tijd.');
			return;
		}

		$applicationId = (string)($interview['applicationId'] ?? '');
		$summary = $this->repository->resolveSummary($run->applicationsById, $run->vacanciesById, $applicationId);
		$location = trim((string)($interview['location'] ?? ''));
		$interviewers = trim((string)($interview['interviewers'] ?? ''));

		$storedUid = trim((string)($interview['calendarEventUid'] ?? ''));
		$uid = ($storedUid !== '') ? $storedUid : (self::URI_PREFIX . $id);
		$ics = $this->icsRenderer->render($uid, $dtstart, $dtend, $summary, $location, $interviewers);
		$prepared = new PreparedInterviewEvent($storedUid, $uid, $uid . '.ics', $ics, $dtstart, $dtend, $summary, $location);

		$this->upsertInterviewEvent($run, $interview, $id, $prepared);

	}//end syncScheduledInterview()

	/**
	 * Create or update one VEVENT, identified by the prepared UID
	 * (design.md D3): probe `getCalendarObjectByUID`; absent ⇒ create;
	 * present ⇒ diff and update only when changed.
	 *
	 * @param InterviewSyncRun $run The run: its target, collecting the outcomes.
	 * @param array<string, mixed> $interview The Interview object (pre-write).
	 * @param string $id The Interview id.
	 * @param PreparedInterviewEvent $prepared The rendered event + its UID identity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 */
	private function upsertInterviewEvent(InterviewSyncRun $run, array $interview, string $id, PreparedInterviewEvent $prepared): void {
		[$probeSucceeded, $existingPath] = $this->probeExistingPath($run, $prepared->uid, $id);
		if ($probeSucceeded === false) {
			return;
		}

		if ($existingPath === null) {
			$this->createEvent($run, $interview, $id, $prepared);
			return;
		}

		$this->updateEventIfChanged($run, $interview, $id, $prepared);

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
	 * @param InterviewSyncRun $run The run: its target, collecting the outcomes.
	 * @param array<string, mixed> $interview The Interview object (pre-write).
	 * @param string $id The Interview id.
	 * @param PreparedInterviewEvent $prepared The rendered event + its UID identity.
	 *
	 * @return void
	 */
	private function createEvent(InterviewSyncRun $run, array $interview, string $id, PreparedInterviewEvent $prepared): void {
		try {
			if (method_exists($run->target->backend, 'createCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::createCalendarObject is not available.');
			}

			$run->target->backend->createCalendarObject($run->target->calendarId, $prepared->objectUri, $prepared->ics);
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($id, 'failed', 'Aanmaken kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		if ($prepared->storedUid === '') {
			$this->repository->persistCalendarEventUid($interview, $id, $prepared->uid);
		}

		$run->results[] = $this->outcome($id, 'created', 'Kalenderafspraak aangemaakt.');

	}//end createEvent()

	/**
	 * Diff the existing stored event against the desired values and update
	 * only when they differ (avoids etag churn on a no-op sync); persists
	 * the UID back onto the Interview if it was not already stored (the
	 * defensive self-heal path for a prior sync that created the event but
	 * crashed before persisting).
	 *
	 * @param InterviewSyncRun $run The run: its target, collecting the outcomes.
	 * @param array<string, mixed> $interview The Interview object (pre-write).
	 * @param string $id The Interview id.
	 * @param PreparedInterviewEvent $prepared The rendered event + its UID identity.
	 *
	 * @return void
	 */
	private function updateEventIfChanged(InterviewSyncRun $run, array $interview, string $id, PreparedInterviewEvent $prepared): void {
		$existingIcs = $this->fetchCalendarData($run->target, $prepared->objectUri);
		if ($existingIcs !== null && $this->icsRenderer->unchanged($existingIcs, $prepared->dtstart, $prepared->dtend, $prepared->summary, $prepared->location) === true) {
			if ($prepared->storedUid === '') {
				$this->repository->persistCalendarEventUid($interview, $id, $prepared->uid);
			}

			$run->results[] = $this->outcome($id, 'unchanged', 'Geen wijziging.');
			return;
		}

		try {
			if (method_exists($run->target->backend, 'updateCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::updateCalendarObject is not available.');
			}

			$run->target->backend->updateCalendarObject($run->target->calendarId, $prepared->objectUri, $prepared->ics);
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($id, 'failed', 'Bijwerken kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		if ($prepared->storedUid === '') {
			$this->repository->persistCalendarEventUid($interview, $id, $prepared->uid);
		}

		$run->results[] = $this->outcome($id, 'updated', 'Kalenderafspraak bijgewerkt.');

	}//end updateEventIfChanged()

	/**
	 * Delete the event of a cancelled Interview (design.md D4), looked up
	 * by the Interview's stored `calendarEventUid` -- a no-op (`unchanged`)
	 * when no UID is stored yet or no event is found under it. The
	 * Interview's own `calendarEventUid` field is left as-is (a historical
	 * pointer; humaniq's own audit log is the record of what happened, not the
	 * calendar).
	 *
	 * @param InterviewSyncRun $run The run: its target, collecting the outcomes.
	 * @param array<string, mixed> $interview The Interview object.
	 * @param string $id The Interview id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 */
	private function removeInterviewEvent(InterviewSyncRun $run, array $interview, string $id): void {
		$storedUid = trim((string)($interview['calendarEventUid'] ?? ''));
		if ($storedUid === '') {
			$run->results[] = $this->outcome($id, 'unchanged', 'Geen kalenderafspraak om te verwijderen.');
			return;
		}

		[$probeSucceeded, $existingPath] = $this->probeExistingPath($run, $storedUid, $id);
		if ($probeSucceeded === false) {
			return;
		}

		if ($existingPath === null) {
			$run->results[] = $this->outcome($id, 'unchanged', 'Geen kalenderafspraak (meer) aanwezig.');
			return;
		}

		try {
			if (method_exists($run->target->backend, 'deleteCalendarObject') === false) {
				throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
			}

			$run->target->backend->deleteCalendarObject($run->target->calendarId, $storedUid . '.ics');
		} catch (Throwable $e) {
			$run->results[] = $this->outcome($id, 'failed', 'Verwijderen kalenderafspraak mislukt: ' . $e->getMessage());
			return;
		}

		$run->results[] = $this->outcome($id, 'removed', 'Kalenderafspraak verwijderd.');

	}//end removeInterviewEvent()

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
