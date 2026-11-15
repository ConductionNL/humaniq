<?php

/**
 * Agenda Controller
 *
 * Three guarded reads: what is planned for a subject, who is free in a window,
 * and how much of each person's contracted time is already planned.
 *
 * WHY AVAILABILITY IS AN ENDPOINT AND THE AGENDA IS A SCREEN
 * ----------------------------------------------------------
 * "Show me the agenda" is a screen. "Who can do this on Thursday" is an
 * integration, and it is the one call a consuming app actually makes (design
 * D5). It answers free HOURS per employee rather than a yes or a no, so a
 * caller can tell a person with one free afternoon from a person with a free
 * week.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AgendaComposer;
use OCA\Humaniq\Service\AgendaSubject;
use OCA\Humaniq\Service\AgendaReadAccess;
use OCA\Humaniq\Service\AvailabilityService;
use OCA\Humaniq\Service\ForwardCapacityService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Humaniq\Support\ResolvesAgendaWindow;

/**
 * Serves the agenda, the availability answer and the forward capacity read.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */
class AgendaController extends Controller {

	use ResolvesAgendaWindow;

	/**
	 * The subject kinds an agenda can be asked for.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECT_TYPES = ['employee', 'orgUnit', 'resource'];

	/**
	 * What a caller who may not read an agenda is told, per subject kind,
	 * and under `hours` what a refused availability or capacity read is told.
	 *
	 * @var array<string, string>
	 */
	private const REFUSALS = [
		'employee' => 'Je kunt deze agenda niet openen. Hij is er voor de medewerker, de leidinggevende, HR en teamgenoten.',
		'orgUnit' => 'Je kunt deze agenda niet openen. Hij is er voor het team, de leidinggevenden en HR.',
		'resource' => 'Je kunt deze agenda niet openen. Meld je eerst aan.',
		'hours' => 'Je kunt deze uren niet opvragen. Ze zijn er voor het team, de leidinggevenden en HR.',
	];

	/**
	 * @param IRequest $request The request object.
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param AgendaComposer $agenda Composes the agenda on read.
	 * @param AvailabilityService $availability Answers free hours per employee.
	 * @param ForwardCapacityService $capacity Reads planned against contracted.
	 * @param AgendaReadAccess $access Who may read which agenda, and the members of a unit.
	 * @param WorkingCalendarReader $workingCalendar openregister's working calendar, read and never owned.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly AgendaComposer $agenda,
		private readonly AvailabilityService $availability,
		private readonly ForwardCapacityService $capacity,
		private readonly AgendaReadAccess $access,
		private readonly WorkingCalendarReader $workingCalendar,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /api/agenda` — what is planned for one subject over a period.
	 *
	 * @param string|null $subjectType `employee`, `orgUnit` or `resource`.
	 * @param string|null $subjectId The subject's id.
	 * @param string|null $from First day (ISO date).
	 * @param string|null $to Last day (ISO date).
	 *
	 * @return JSONResponse The entries, 400 when the parameters are unusable, or 403 when the caller may not read this agenda.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	#[NoAdminRequired]
	public function agenda(
		?string $subjectType = null,
		?string $subjectId = null,
		?string $from = null,
		?string $to = null,
	): JSONResponse {
		$subjectType = trim((string)$subjectType);
		$subjectId = trim((string)$subjectId);
		if (in_array($subjectType, self::SUBJECT_TYPES, true) === false || $subjectId === '') {
			return new JSONResponse(
				['error' => 'subjectType moet employee, orgUnit of resource zijn, met een subjectId.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$window = $this->window(from: $from, to: $to);
		if ($window === null) {
			return self::unusableWindow();
		}

		return $this->grantedAgenda(subject: new AgendaSubject(type: $subjectType, id: $subjectId), window: $window);
	}//end agenda()

	/**
	 * The agenda of a subject the caller may read, with only the entries
	 * their grant shows; 403 when they may not read it.
	 *
	 * @param AgendaSubject                                   $subject The subject asked about.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $window  The period.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function grantedAgenda(AgendaSubject $subject, array $window): JSONResponse {
		$grant = $this->access->grant(subjectType: $subject->type, subjectId: $subject->id);
		if ($grant === null) {
			return new JSONResponse(['error' => self::REFUSALS[$subject->type]], Http::STATUS_FORBIDDEN);
		}

		$entries = $this->composedEntries(subject: $subject, window: $window);

		return new JSONResponse([
			'subjectType' => $subject->type,
			'subjectId' => $subject->id,
			'from' => $window[0]->format('Y-m-d'),
			'to' => $window[1]->format('Y-m-d'),
			'entries' => $this->access->visibleEntries(entries: $entries, grant: $grant),
		]);
	}//end grantedAgenda()

	/**
	 * Every entry of the subject over the period; an org unit stands for its
	 * members on the first day.
	 *
	 * @param AgendaSubject                                   $subject The subject asked about.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $window  The period.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function composedEntries(AgendaSubject $subject, array $window): array {
		$members = [];
		if ($subject->type === 'orgUnit') {
			$members = $this->access->members(orgUnitId: $subject->id, onDate: $window[0]);
		}

		return $this->agenda->compose(
			sources: $this->sources(from: $window[0], to: $window[1]),
			query: [
				'subjectType' => $subject->type,
				'subjectId' => $subject->id,
				'from' => $window[0]->format('Y-m-d'),
				'to' => $window[1]->format('Y-m-d'),
				'memberEmployeeIds' => $members,
			]
		);
	}//end composedEntries()

	/**
	 * `GET /api/availability` — who is free in a window, in hours.
	 *
	 * @param string|null $from First day (ISO date).
	 * @param string|null $to Last day (ISO date).
	 * @param string|null $orgUnitId Narrow to one org unit.
	 * @param string|null $competences Comma-separated competence codes every answered employee must hold.
	 *
	 * @return JSONResponse The answer, 400 when the window is unusable, or 403 when the caller may not read these hours.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	#[NoAdminRequired]
	public function availability(
		?string $from = null,
		?string $to = null,
		?string $orgUnitId = null,
		?string $competences = null,
	): JSONResponse {
		$window = $this->window(from: $from, to: $to);
		if ($window === null) {
			return self::unusableWindow();
		}

		$orgUnitId = trim((string)$orgUnitId);
		$scope = $this->access->employeeScope(orgUnitId: $orgUnitId, onDate: $window[0]);
		if ($scope === null) {
			return self::hoursRefused();
		}

		$codes = $this->codes($competences);

		return new JSONResponse([
			'from' => $window[0]->format('Y-m-d'),
			'to' => $window[1]->format('Y-m-d'),
			'orgUnitId' => ($orgUnitId === '' ? null : $orgUnitId),
			'competences' => $codes,
			'employees' => $this->availability->availability(
				employeeIds: $scope['ids'],
				from: $window[0],
				to: $window[1],
				sources: $this->visibleSourcesIn(scope: $scope, window: $window),
				requiredCompetences: $codes
			),
		]);
	}//end availability()

	/**
	 * `GET /api/capacity` — planned against contracted, forward from a date.
	 *
	 * @param string|null $from First day (ISO date).
	 * @param string|null $to Last day (ISO date).
	 * @param string|null $orgUnitId Narrow to one org unit.
	 *
	 * @return JSONResponse The answer, 400 when the window is unusable, or 403 when the caller may not read these hours.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C03
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	#[NoAdminRequired]
	public function capacity(?string $from = null, ?string $to = null, ?string $orgUnitId = null): JSONResponse {
		$window = $this->window(from: $from, to: $to);
		if ($window === null) {
			return self::unusableWindow();
		}

		$scope = $this->access->employeeScope(orgUnitId: trim((string)$orgUnitId), onDate: $window[0]);
		if ($scope === null) {
			return self::hoursRefused();
		}

		return new JSONResponse(
			$this->capacity->capacity(
				employeeIds: $scope['ids'],
				from: $window[0],
				to: $window[1],
				sources: $this->visibleSourcesIn(scope: $scope, window: $window)
			)
		);
	}//end capacity()

	/**
	 * The sources of the period, narrowed to what the caller's scope may see.
	 *
	 * @param array<string, mixed>                              $scope  The caller's employee scope.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $window The period.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	private function visibleSourcesIn(array $scope, array $window): array {
		return $this->access->visibleSources(sources: $this->sources(from: $window[0], to: $window[1]), scope: $scope);
	}//end visibleSourcesIn()

	/**
	 * The refusal of an hours read the caller may not make.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	private static function hoursRefused(): JSONResponse {
		return new JSONResponse(['error' => self::REFUSALS['hours']], Http::STATUS_FORBIDDEN);
	}//end hoursRefused()

	/**
	 * Every row the composer and the two queries read, fetched once.
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to Last day.
	 *
	 * @return array<string, mixed> The sources.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function sources(DateTimeImmutable $from, DateTimeImmutable $to): array {
		$calendar = $this->workingCalendar->nonWorkingDates(from: $from, to: $to);

		return [
			'assignments' => $this->gateway->loadAll('RosterAssignment'),
			'shifts' => $this->gateway->loadAll('Shift'),
			'leaveRequests' => $this->gateway->loadAll('LeaveRequest'),
			'sickLeaveCases' => $this->gateway->loadAll('SickLeaveCase'),
			'interviews' => $this->gateway->loadAll('Interview'),
			'secondments' => $this->gateway->loadAll('Secondment'),
			'bookings' => $this->gateway->loadAll('ResourceBooking'),
			'subscriptions' => $this->gateway->loadAll('CalendarSubscription'),
			'workingPatterns' => $this->gateway->loadAll('WorkingPattern'),
			'nonWorkingTimes' => $this->gateway->loadAll('NonWorkingTime'),
			'competences' => $this->gateway->loadAll('EmployeeCompetence'),
			// null when openregister's calendar could not be read, which makes
			// every contracted-hours answer say it is pattern-only rather than
			// quietly counting a feestdag as a working day.
			'nonWorkingDates' => $calendar['dates'],
		];
	}//end sources()

	/**
	 * The competence codes a caller asked for.
	 *
	 * @param string|null $competences The comma-separated codes.
	 *
	 * @return array<int, string> The codes.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function codes(?string $competences): array {
		$codes = [];
		foreach (explode(',', (string)$competences) as $code) {
			$code = trim($code);
			if ($code !== '') {
				$codes[$code] = true;
			}
		}

		return array_keys($codes);
	}//end codes()
}//end class
