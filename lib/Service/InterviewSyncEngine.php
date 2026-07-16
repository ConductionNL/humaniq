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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use RuntimeException;
use Throwable;

/**
 * Per-Interview status dispatch, idempotent upsert/remove, and the
 * duck-typed CalDavBackend I/O.
 */
class InterviewSyncEngine
{

    /**
     * @var string
     */
    private const URI_PREFIX = 'hrmq-interview-';


    /**
     * @param InterviewRepository  $repository  Interview/Application/Vacancy data access + PUT-semantic persist.
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
     * @param InterviewCalendarTarget               $target           The resolved sync target.
     * @param array<string, mixed>                  $interview        The Interview object.
     * @param string                                 $id                The Interview id.
     * @param array<string, array<string, string>>  $applicationsById Application index (candidateName/vacancyId).
     * @param array<string, string>                 $vacanciesById    Vacancy id -> title index.
     * @param string|null                            $from              Optional bound (Y-m-d).
     * @param array<int, array<string, mixed>>      &$results          Outcome accumulator.
     *
     * @return void
     *
     * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
     * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
     */
    public function syncInterview(
        InterviewCalendarTarget $target,
        array $interview,
        string $id,
        array $applicationsById,
        array $vacanciesById,
        ?string $from,
        array &$results
    ): void {
        $status = (string) ($interview['status'] ?? '');

        if ($status === 'cancelled') {
            $this->removeInterviewEvent($target, $interview, $id, $results);
            return;
        }

        if ($status === 'completed') {
            // design.md D4: a completed Interview's last-synced event stays
            // on the calendar as completed history -- no further sync
            // touches it.
            $results[] = $this->outcome($id, 'unchanged', 'Interview is voltooid; kalenderafspraak blijft ongewijzigd als geschiedenis.');
            return;
        }

        if ($status !== 'scheduled') {
            $results[] = $this->outcome($id, 'unchanged', 'Onbekende status; overgeslagen.');
            return;
        }

        $this->syncScheduledInterview($target, $interview, $id, $applicationsById, $vacanciesById, $from, $results);

    }//end syncInterview()


    /**
     * Validate/bound/render one `scheduled` Interview, then upsert it.
     *
     * @param InterviewCalendarTarget               $target           The resolved sync target.
     * @param array<string, mixed>                  $interview        The Interview object.
     * @param string                                 $id                The Interview id.
     * @param array<string, array<string, string>>  $applicationsById Application index (candidateName/vacancyId).
     * @param array<string, string>                 $vacanciesById    Vacancy id -> title index.
     * @param string|null                            $from              Optional bound (Y-m-d).
     * @param array<int, array<string, mixed>>      &$results          Outcome accumulator.
     *
     * @return void
     *
     * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
     */
    private function syncScheduledInterview(
        InterviewCalendarTarget $target,
        array $interview,
        string $id,
        array $applicationsById,
        array $vacanciesById,
        ?string $from,
        array &$results
    ): void {
        $scheduledStart = trim((string) ($interview['scheduledStart'] ?? ''));
        $scheduledEnd   = trim((string) ($interview['scheduledEnd'] ?? ''));
        if ($scheduledStart === '' || $scheduledEnd === '') {
            $results[] = $this->outcome($id, 'failed', 'Interview mist scheduledStart/scheduledEnd; kan niet gesynchroniseerd worden.');
            return;
        }

        if ($from !== null && $this->icsRenderer->scheduledStartOnOrAfter($scheduledStart, $from) === false) {
            // Out of the --from bound; an already-synced event stays untouched.
            return;
        }

        $dtstart = $this->icsRenderer->compactDateTime($scheduledStart);
        $dtend   = $this->icsRenderer->compactDateTime($scheduledEnd);
        if ($dtstart === null || $dtend === null) {
            $results[] = $this->outcome($id, 'failed', 'Interview scheduledStart/scheduledEnd is geen geldige datum/tijd.');
            return;
        }

        $applicationId = (string) ($interview['applicationId'] ?? '');
        $summary       = $this->repository->resolveSummary($applicationsById, $vacanciesById, $applicationId);
        $location      = trim((string) ($interview['location'] ?? ''));
        $interviewers  = trim((string) ($interview['interviewers'] ?? ''));

        $storedUid = trim((string) ($interview['calendarEventUid'] ?? ''));
        $uid       = ($storedUid !== '') ? $storedUid : (self::URI_PREFIX.$id);
        $ics       = $this->icsRenderer->render($uid, $dtstart, $dtend, $summary, $location, $interviewers);
        $prepared  = new PreparedInterviewEvent($storedUid, $uid, $uid.'.ics', $ics, $dtstart, $dtend, $summary, $location);

        $this->upsertInterviewEvent($target, $interview, $id, $prepared, $results);

    }//end syncScheduledInterview()


    /**
     * Create or update one VEVENT, identified by the prepared UID
     * (design.md D3): probe `getCalendarObjectByUID`; absent ⇒ create;
     * present ⇒ diff and update only when changed.
     *
     * @param InterviewCalendarTarget          $target    The resolved sync target.
     * @param array<string, mixed>             $interview The Interview object (pre-write).
     * @param string                           $id        The Interview id.
     * @param PreparedInterviewEvent           $prepared  The rendered event + its UID identity.
     * @param array<int, array<string, mixed>> &$results  Outcome accumulator.
     *
     * @return void
     *
     * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
     */
    private function upsertInterviewEvent(InterviewCalendarTarget $target, array $interview, string $id, PreparedInterviewEvent $prepared, array &$results): void
    {
        [$probeSucceeded, $existingPath] = $this->probeExistingPath($target, $prepared->uid, $id, $results);
        if ($probeSucceeded === false) {
            return;
        }

        if ($existingPath === null) {
            $this->createEvent($target, $interview, $id, $prepared, $results);
            return;
        }

        $this->updateEventIfChanged($target, $interview, $id, $prepared, $results);

    }//end upsertInterviewEvent()


    /**
     * Probe `getCalendarObjectByUID` for the given UID, scoped to the
     * configured calendar. Returns `[true, $path]` on success (`$path` null
     * means not found) or `[false, null]` after appending a `failed`
     * outcome.
     *
     * @param InterviewCalendarTarget          $target   The resolved sync target.
     * @param string                           $uid      The VEVENT UID to look up.
     * @param string                           $id       The Interview id, for the outcome row.
     * @param array<int, array<string, mixed>> &$results Outcome accumulator.
     *
     * @return array{0: bool, 1: mixed}
     */
    private function probeExistingPath(InterviewCalendarTarget $target, string $uid, string $id, array &$results): array
    {
        try {
            $path = (method_exists($target->backend, 'getCalendarObjectByUID') === true)
                ? $target->backend->getCalendarObjectByUID($target->principal, $uid, $target->calendarUri)
                : null;
        } catch (Throwable $e) {
            $results[] = $this->outcome($id, 'failed', 'Kon bestaand kalenderobject niet opzoeken: '.$e->getMessage());
            return [false, null];
        }

        return [true, $path];

    }//end probeExistingPath()


    /**
     * Create the calendar object and, unless the UID was already stored,
     * persist it back onto the Interview (design.md D3).
     *
     * @param InterviewCalendarTarget          $target    The resolved sync target.
     * @param array<string, mixed>             $interview The Interview object (pre-write).
     * @param string                           $id        The Interview id.
     * @param PreparedInterviewEvent           $prepared  The rendered event + its UID identity.
     * @param array<int, array<string, mixed>> &$results  Outcome accumulator.
     *
     * @return void
     */
    private function createEvent(InterviewCalendarTarget $target, array $interview, string $id, PreparedInterviewEvent $prepared, array &$results): void
    {
        try {
            if (method_exists($target->backend, 'createCalendarObject') === false) {
                throw new RuntimeException('CalDavBackend::createCalendarObject is not available.');
            }

            $target->backend->createCalendarObject($target->calendarId, $prepared->objectUri, $prepared->ics);
        } catch (Throwable $e) {
            $results[] = $this->outcome($id, 'failed', 'Aanmaken kalenderafspraak mislukt: '.$e->getMessage());
            return;
        }

        if ($prepared->storedUid === '') {
            $this->repository->persistCalendarEventUid($interview, $id, $prepared->uid);
        }

        $results[] = $this->outcome($id, 'created', 'Kalenderafspraak aangemaakt.');

    }//end createEvent()


    /**
     * Diff the existing stored event against the desired values and update
     * only when they differ (avoids etag churn on a no-op sync); persists
     * the UID back onto the Interview if it was not already stored (the
     * defensive self-heal path for a prior sync that created the event but
     * crashed before persisting).
     *
     * @param InterviewCalendarTarget          $target    The resolved sync target.
     * @param array<string, mixed>             $interview The Interview object (pre-write).
     * @param string                           $id        The Interview id.
     * @param PreparedInterviewEvent           $prepared  The rendered event + its UID identity.
     * @param array<int, array<string, mixed>> &$results  Outcome accumulator.
     *
     * @return void
     */
    private function updateEventIfChanged(InterviewCalendarTarget $target, array $interview, string $id, PreparedInterviewEvent $prepared, array &$results): void
    {
        $existingIcs = $this->fetchCalendarData($target, $prepared->objectUri);
        if ($existingIcs !== null && $this->icsRenderer->unchanged($existingIcs, $prepared->dtstart, $prepared->dtend, $prepared->summary, $prepared->location) === true) {
            if ($prepared->storedUid === '') {
                $this->repository->persistCalendarEventUid($interview, $id, $prepared->uid);
            }

            $results[] = $this->outcome($id, 'unchanged', 'Geen wijziging.');
            return;
        }

        try {
            if (method_exists($target->backend, 'updateCalendarObject') === false) {
                throw new RuntimeException('CalDavBackend::updateCalendarObject is not available.');
            }

            $target->backend->updateCalendarObject($target->calendarId, $prepared->objectUri, $prepared->ics);
        } catch (Throwable $e) {
            $results[] = $this->outcome($id, 'failed', 'Bijwerken kalenderafspraak mislukt: '.$e->getMessage());
            return;
        }

        if ($prepared->storedUid === '') {
            $this->repository->persistCalendarEventUid($interview, $id, $prepared->uid);
        }

        $results[] = $this->outcome($id, 'updated', 'Kalenderafspraak bijgewerkt.');

    }//end updateEventIfChanged()


    /**
     * Delete the event of a cancelled Interview (design.md D4), looked up
     * by the Interview's stored `calendarEventUid` -- a no-op (`unchanged`)
     * when no UID is stored yet or no event is found under it. The
     * Interview's own `calendarEventUid` field is left as-is (a historical
     * pointer; hrmq's own audit log is the record of what happened, not the
     * calendar).
     *
     * @param InterviewCalendarTarget          $target    The resolved sync target.
     * @param array<string, mixed>             $interview The Interview object.
     * @param string                           $id        The Interview id.
     * @param array<int, array<string, mixed>> &$results  Outcome accumulator.
     *
     * @return void
     *
     * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
     */
    private function removeInterviewEvent(InterviewCalendarTarget $target, array $interview, string $id, array &$results): void
    {
        $storedUid = trim((string) ($interview['calendarEventUid'] ?? ''));
        if ($storedUid === '') {
            $results[] = $this->outcome($id, 'unchanged', 'Geen kalenderafspraak om te verwijderen.');
            return;
        }

        [$probeSucceeded, $existingPath] = $this->probeExistingPath($target, $storedUid, $id, $results);
        if ($probeSucceeded === false) {
            return;
        }

        if ($existingPath === null) {
            $results[] = $this->outcome($id, 'unchanged', 'Geen kalenderafspraak (meer) aanwezig.');
            return;
        }

        try {
            if (method_exists($target->backend, 'deleteCalendarObject') === false) {
                throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
            }

            $target->backend->deleteCalendarObject($target->calendarId, $storedUid.'.ics');
        } catch (Throwable $e) {
            $results[] = $this->outcome($id, 'failed', 'Verwijderen kalenderafspraak mislukt: '.$e->getMessage());
            return;
        }

        $results[] = $this->outcome($id, 'removed', 'Kalenderafspraak verwijderd.');

    }//end removeInterviewEvent()


    /**
     * Fetch a calendar object's raw ICS text, or null on any resolution
     * failure (design.md D3 diffing input).
     *
     * @param InterviewCalendarTarget $target    The resolved sync target.
     * @param string                  $objectUri The deterministic object URI.
     *
     * @return string|null
     */
    private function fetchCalendarData(InterviewCalendarTarget $target, string $objectUri): ?string
    {
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
     * @param string $status   `created|updated|removed|unchanged|failed`.
     * @param string $message  A human-readable outcome message.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $sourceId, string $status, string $message): array
    {
        return [
            'type'     => 'interview',
            'sourceId' => $sourceId,
            'status'   => $status,
            'message'  => $message,
        ];

    }//end outcome()


}//end class
