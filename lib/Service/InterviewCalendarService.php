<?php

/**
 * Interview Calendar Service
 *
 * Projects scheduled recruiting Interviews onto one configured shared
 * Nextcloud calendar as timed VEVENTs (interview-scheduling design.md
 * D1-D6) — a fork of `LeaveCalendarService` for a second object type. hrmq
 * holds no calendar storage of its own — the sync is an operator-demand
 * upsert/remove pass (occ hrmq:interview:sync + a guarded manifest action),
 * not an event listener or background job.
 *
 * This class is a thin orchestrator: it resolves the sync target (the
 * duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend`, string
 * class name, `mixed`, try/catch + `method_exists` probes -- the exact
 * `LeaveCalendarService`/`PayrollGLPostService` idiom) and loops the
 * Interview set; every per-Interview decision (status dispatch, idempotent
 * upsert/remove, orphan reconciliation) lives in `InterviewSyncEngine`, ICS
 * rendering/diffing lives in `InterviewIcsRenderer`, and Interview/
 * Application/Vacancy data access + the PUT-semantic `calendarEventUid`
 * persist live in `InterviewRepository` (single-responsibility split of
 * what would otherwise be one very large class).
 *
 * Unlike `LeaveCalendarService` (which derives its UID at render time and
 * never persists it, because it always sweeps the entire live set), this
 * service PERSISTS `calendarEventUid` back onto the Interview after the
 * first successful create (design.md D3) — an inspectable idempotency
 * marker, since interviews are scheduled/rescheduled/cancelled as
 * individual, direct actions. Every later sync looks the event up by that
 * stored UID rather than recomputing it, so a no-op resync writes nothing.
 *
 * AVG boundary (design.md D5): the rendered SUMMARY carries the candidate
 * name and vacancy title (the whole point of the event), `interviewers`
 * renders as plain DESCRIPTION text. `email`, `phone`, `cvFile`,
 * `motivation`, `talentPoolOptIn`, `rejectedDate`, `retentionExpiryDate` are
 * never loaded into the render path, and no event ever carries an
 * ATTENDEE/ORGANIZER property (so no iMIP scheduling mail is ever
 * triggered).
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upserts/removes scheduled-interview VEVENTs on the configured shared calendar.
 */
class InterviewCalendarService {

	/**
	 * The duck-typed CalDAV backend's FQCN, resolved from the container by
	 * string (design.md D1) — never a `use` import, so hrmq carries no
	 * composer/info.xml dependency on the dav app.
	 *
	 * @var string
	 */
	private const CALDAV_BACKEND_CLASS = 'OCA\DAV\CalDAV\CalDavBackend';

	/**
	 * @var InterviewRepository
	 */
	private readonly InterviewRepository $repository;

	/**
	 * @var InterviewSyncEngine
	 */
	private readonly InterviewSyncEngine $engine;

	/**
	 * @var InterviewOrphanReconciler
	 */
	private readonly InterviewOrphanReconciler $orphanReconciler;

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
		$this->repository = new InterviewRepository($container, $settingsService, $logger);
		$this->engine = new InterviewSyncEngine($this->repository, new InterviewIcsRenderer());
		$this->orphanReconciler = new InterviewOrphanReconciler($logger);

	}//end __construct()

	/**
	 * Run the full sync: upsert one timed VEVENT per `scheduled` Interview,
	 * remove the event of any `cancelled` Interview, leave `completed`
	 * Interviews' events untouched, and reconcile orphaned
	 * `hrmq-interview-*.ics` events whose source was hard-deleted from the
	 * register (design.md D1-D6).
	 *
	 * @param string|null $from Optional ISO date (Y-m-d). Bounds the upsert
	 *                          set to Interviews whose scheduledStart is
	 *                          on/after this date; an out-of-bound
	 *                          Interview's already-synced event stays
	 *                          untouched. Reconciliation always uses the
	 *                          full live-id set regardless of `--from`.
	 *
	 * @return array<int, array<string, mixed>> One outcome row per touched
	 *                                          Interview, or a single
	 *                                          `skipped-no-calendar` row.
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-006
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-007
	 */
	public function sync(?string $from = null): array {
		$target = $this->resolveTarget();
		if (is_string($target) === true) {
			return [$this->skipOutcome($target)];
		}

		$applicationsById = $this->repository->loadApplicationIndex();
		$vacanciesById = $this->repository->loadVacancyTitleIndex();

		$results = [];
		$liveIds = [];

		foreach ($this->repository->loadAllInterviews() as $interview) {
			$id = (string)($interview['id'] ?? $interview['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$liveIds[] = $id;
			$this->engine->syncInterview($target, $interview, $id, $applicationsById, $vacanciesById, $from, $results);
		}

		$this->orphanReconciler->reconcile($target, $liveIds, $results);

		return $results;
	}//end sync()

	/**
	 * Sync exactly one Interview (the guarded manifest action's single-object
	 * trigger, design.md D6): resolves the configured calendar, loads the
	 * one Interview by id, and returns its single outcome row.
	 *
	 * @param string $interviewId The Interview id.
	 *
	 * @return array<string, mixed> One outcome row.
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
	 */
	public function syncOne(string $interviewId): array {
		$target = $this->resolveTarget();
		if (is_string($target) === true) {
			return $this->skipOutcome($target);
		}

		$interview = $this->repository->find($interviewId);
		if ($interview === null) {
			return $this->outcome($interviewId, 'failed', 'Interview ' . $interviewId . ' kon niet worden geladen.');
		}

		$applicationsById = $this->repository->loadApplicationIndex();
		$vacanciesById = $this->repository->loadVacancyTitleIndex();

		$results = [];
		$this->engine->syncInterview($target, $interview, $interviewId, $applicationsById, $vacanciesById, null, $results);

		return $results[0] ?? $this->outcome($interviewId, 'unchanged', 'Geen wijziging.');
	}//end syncOne()

	/**
	 * Resolve the sync target (design.md D1/D6): the configured principal/
	 * URI, the duck-typed CalDavBackend, and the resolved calendar. Returns
	 * the human-readable skip reason instead of the target when any step
	 * misses.
	 *
	 * @return InterviewCalendarTarget|string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-006
	 */
	private function resolveTarget(): InterviewCalendarTarget|string {
		$principal = $this->settingsService->getInterviewCalendarPrincipal();
		$uri = $this->settingsService->getInterviewCalendarUri();
		if ($principal === '' || $uri === '') {
			return 'interview_calendar_principal/interview_calendar_uri is niet geconfigureerd.';
		}

		$backend = $this->resolveBackend();
		if ($backend === null) {
			return 'De CalDAV-back-end kon niet worden opgelost (dav-app afwezig of onverwachte methodesignatuur).';
		}

		$calendar = $this->probeCalendar($backend, $principal, $uri);
		if ($calendar === null) {
			return sprintf('Kalender "%s" niet gevonden voor principal "%s".', $uri, $principal);
		}

		return new InterviewCalendarTarget($backend, $calendar['id'], $principal, $uri);
	}//end resolveTarget()

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
		} catch (Throwable $e) {
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
				// REQ-INTV-006: a resolution miss degrades to
				// skipped-no-calendar and SHALL log nothing above INFO --
				// this is an expected, healthy degradation path.
				$this->logger->info('InterviewCalendarService: CalDavBackend mist methode ' . $method . '; kalendersync overgeslagen.');
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
		} catch (Throwable $e) {
			return null;
		}

		return is_array($calendar) === true ? $calendar : null;
	}//end probeCalendar()

	/**
	 * Build the `skipped-no-calendar` outcome row that ends a run cleanly
	 * (design.md D1/D6, REQ-INTV-006) — the exit-0/happy-skip path.
	 *
	 * @param string $message The human-readable reason.
	 *
	 * @return array<string, mixed>
	 */
	private function skipOutcome(string $message): array {
		return $this->outcome(null, 'skipped-no-calendar', $message);
	}//end skipOutcome()

	/**
	 * Build one run-level outcome row (used only for the skip/failed cases
	 * this orchestrator itself produces; per-Interview outcomes come from
	 * `InterviewSyncEngine`).
	 *
	 * @param string|null $sourceId The Interview id, or null for a run-level outcome.
	 * @param string $status `failed|skipped-no-calendar`.
	 * @param string $message A human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(?string $sourceId, string $status, string $message): array {
		return [
			'type' => ($sourceId === null) ? null : 'interview',
			'sourceId' => $sourceId,
			'status' => $status,
			'message' => $message,
		];

	}//end outcome()

}//end class
