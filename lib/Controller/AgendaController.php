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
use OCA\Humaniq\Service\AvailabilityService;
use OCA\Humaniq\Service\ForwardCapacityService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the agenda, the availability answer and the forward capacity read.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */
class AgendaController extends Controller {

	/**
	 * The subject kinds an agenda can be asked for.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECT_TYPES = ['employee', 'orgUnit', 'resource'];

	/**
	 * @param IRequest $request The request object.
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param AgendaComposer $agenda Composes the agenda on read.
	 * @param AvailabilityService $availability Answers free hours per employee.
	 * @param ForwardCapacityService $capacity Reads planned against contracted.
	 * @param OrgResolutionService $orgResolution The shared org-chain rules.
	 * @param WorkingCalendarReader $workingCalendar openregister's working calendar, read and never owned.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly AgendaComposer $agenda,
		private readonly AvailabilityService $availability,
		private readonly ForwardCapacityService $capacity,
		private readonly OrgResolutionService $orgResolution,
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
	 * @return JSONResponse The entries, or 400 when the parameters are unusable.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
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
			return new JSONResponse(
				['error' => 'from en to moeten geldige datums zijn, met to op of na from.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$members = [];
		if ($subjectType === 'orgUnit') {
			$members = $this->members(orgUnitId: $subjectId, on: $window[0]);
		}

		return new JSONResponse([
			'subjectType' => $subjectType,
			'subjectId' => $subjectId,
			'from' => $window[0]->format('Y-m-d'),
			'to' => $window[1]->format('Y-m-d'),
			'entries' => $this->agenda->compose(
				sources: $this->sources(from: $window[0], to: $window[1]),
				subjectType: $subjectType,
				subjectId: $subjectId,
				from: $window[0]->format('Y-m-d'),
				to: $window[1]->format('Y-m-d'),
				memberEmployeeIds: $members
			),
		]);
	}//end agenda()

	/**
	 * `GET /api/availability` — who is free in a window, in hours.
	 *
	 * @param string|null $from First day (ISO date).
	 * @param string|null $to Last day (ISO date).
	 * @param string|null $orgUnitId Narrow to one org unit.
	 * @param string|null $competences Comma-separated competence codes every answered employee must hold.
	 *
	 * @return JSONResponse The answer, or 400 when the window is unusable.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
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
			return new JSONResponse(
				['error' => 'from en to moeten geldige datums zijn, met to op of na from.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$orgUnitId = trim((string)$orgUnitId);
		$employeeIds = $this->employeeScope(orgUnitId: $orgUnitId, on: $window[0]);

		return new JSONResponse([
			'from' => $window[0]->format('Y-m-d'),
			'to' => $window[1]->format('Y-m-d'),
			'orgUnitId' => ($orgUnitId === '' ? null : $orgUnitId),
			'competences' => $this->codes($competences),
			'employees' => $this->availability->availability(
				employeeIds: $employeeIds,
				from: $window[0],
				to: $window[1],
				sources: $this->sources(from: $window[0], to: $window[1]),
				requiredCompetences: $this->codes($competences)
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
	 * @return JSONResponse The answer, or 400 when the window is unusable.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C03
	 */
	#[NoAdminRequired]
	public function capacity(?string $from = null, ?string $to = null, ?string $orgUnitId = null): JSONResponse {
		$window = $this->window(from: $from, to: $to);
		if ($window === null) {
			return new JSONResponse(
				['error' => 'from en to moeten geldige datums zijn, met to op of na from.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$orgUnitId = trim((string)$orgUnitId);

		return new JSONResponse(
			$this->capacity->capacity(
				employeeIds: $this->employeeScope(orgUnitId: $orgUnitId, on: $window[0]),
				from: $window[0],
				to: $window[1],
				sources: $this->sources(from: $window[0], to: $window[1])
			)
		);
	}//end capacity()

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
	 * The employees an answer covers: one org unit's members, or everybody.
	 *
	 * @param string $orgUnitId The org unit, or an empty string for all.
	 * @param DateTimeImmutable $on The date a placement must be active on.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function employeeScope(string $orgUnitId, DateTimeImmutable $on): array {
		if ($orgUnitId !== '') {
			return $this->members(orgUnitId: $orgUnitId, on: $on);
		}

		$employees = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			$id = trim((string)($employee['id'] ?? ''));
			if ($id !== '') {
				$employees[] = $id;
			}
		}

		return $employees;
	}//end employeeScope()

	/**
	 * The employees placed in one org unit on one date.
	 *
	 * @param string $orgUnitId The org unit.
	 * @param DateTimeImmutable $on The date the placement must be active on.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function members(string $orgUnitId, DateTimeImmutable $on): array {
		$members = [];
		foreach ($this->gateway->findFiltered('OrgAssignment', ['orgUnitId' => $orgUnitId]) as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $on->format('Y-m-d')) === false) {
				continue;
			}

			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			if ($employeeId !== '') {
				$members[$employeeId] = true;
			}
		}

		return array_keys($members);
	}//end members()

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

	/**
	 * The period asked about, or null when it is unusable.
	 *
	 * @param string|null $from First day (ISO date).
	 * @param string|null $to Last day (ISO date).
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null The window.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function window(?string $from, ?string $to): ?array {
		try {
			$start = new DateTimeImmutable(substr(trim((string)$from), 0, 10));
			$end = new DateTimeImmutable(substr(trim((string)$to), 0, 10));
		} catch (\Throwable $e) {
			return null;
		}

		if ($end < $start) {
			return null;
		}

		return [$start, $end];
	}//end window()
}//end class
