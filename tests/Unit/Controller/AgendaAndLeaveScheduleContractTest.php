<?php

/**
 * Wire-contract tests for the agenda and leave-schedule endpoints
 *
 * Three endpoints went live with no test that calls them: `GET /api/agenda`,
 * `GET /api/leave/schedule` and `GET /api/leave/coverage`. What is pinned here
 * is the part of a wire contract a consumer actually depends on and a refactor
 * can silently change: the status code a bad request answers with, and the key
 * set of a good one.
 *
 * The refusals are asserted with the LEAST privileged input that should be
 * refused, so a widening of the guard shows up as a 200 where a 400 belongs.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\AgendaController;
use OCA\Humaniq\Controller\LeaveScheduleController;
use OCA\Humaniq\Service\AgendaComposer;
use OCA\Humaniq\Service\AvailabilityService;
use OCA\Humaniq\Service\DepartmentLeaveScheduleService;
use OCA\Humaniq\Service\ForwardCapacityService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\LeaveCoverageService;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The three endpoints answer the shape their consumers read.
 */
class AgendaAndLeaveScheduleContractTest extends TestCase {

	/**
	 * Build the agenda controller with every collaborator doubled.
	 *
	 * @return AgendaController The subject.
	 */
	private function agendaController(): AgendaController {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturn([]);
		$gateway->method('findFiltered')->willReturn([]);

		$composer = $this->createMock(AgendaComposer::class);
		$composer->method('compose')->willReturn([]);

		$calendar = $this->createMock(WorkingCalendarReader::class);
		$calendar->method('nonWorkingDates')->willReturn(
			[
				'dates' => null,
				'resolved' => false,
				'reason' => 'no-working-calendar-service',
			]
		);

		return new AgendaController(
			$this->createMock(IRequest::class),
			$gateway,
			$composer,
			$this->createMock(AvailabilityService::class),
			$this->createMock(ForwardCapacityService::class),
			$this->createMock(OrgResolutionService::class),
			$calendar
		);
	}//end agendaController()

	/**
	 * Build the leave-schedule controller with every collaborator doubled.
	 *
	 * @param array<string, mixed>|null $resolved What the caller-scoped read answers with.
	 *
	 * @return LeaveScheduleController The subject.
	 */
	private function leaveController(?array $resolved = null): LeaveScheduleController {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturn([]);
		$gateway->method('findFiltered')->willReturn([]);
		$gateway->method('findObjectData')->willReturn([]);

		$schedule = $this->createMock(DepartmentLeaveScheduleService::class);
		$schedule->method('compose')->willReturn([]);

		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($resolved);

		return new LeaveScheduleController(
			$this->createMock(IRequest::class),
			$gateway,
			$schedule,
			$this->createMock(LeaveCoverageService::class),
			$this->createMock(OrgResolutionService::class),
			$rbac
		);
	}//end leaveController()

	/**
	 * `GET /api/agenda` refuses a subject kind it does not serve.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function testTheAgendaRefusesAnUnknownSubjectKind(): void {
		$response = $this->agendaController()->agenda(
			subjectType: 'department',
			subjectId: 'unit-1',
			from: '2026-09-01',
			to: '2026-09-07'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testTheAgendaRefusesAnUnknownSubjectKind()

	/**
	 * `GET /api/agenda` answers the window it was asked for, and the entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function testTheAgendaAnswersTheWindowAndTheEntries(): void {
		$response = $this->agendaController()->agenda(
			subjectType: 'employee',
			subjectId: 'employee-1',
			from: '2026-09-01',
			to: '2026-09-07'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['subjectType', 'subjectId', 'from', 'to', 'entries'],
			array_keys($response->getData())
		);
		$this->assertSame('2026-09-01', $response->getData()['from']);
		$this->assertSame('2026-09-07', $response->getData()['to']);
	}//end testTheAgendaAnswersTheWindowAndTheEntries()

	/**
	 * `GET /api/leave/schedule` refuses a request that names no org unit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	public function testTheScheduleRefusesWithoutAnOrgUnit(): void {
		$response = $this->leaveController()->schedule(orgUnitId: '', from: '2026-09-01', to: '2026-09-07');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testTheScheduleRefusesWithoutAnOrgUnit()

	/**
	 * `GET /api/leave/schedule` answers the member count beside the entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	public function testTheScheduleAnswersTheMemberCountAndTheEntries(): void {
		$response = $this->leaveController()->schedule(
			orgUnitId: 'unit-1',
			from: '2026-09-01',
			to: '2026-09-07'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['orgUnitId', 'from', 'to', 'memberCount', 'entries'],
			array_keys($response->getData())
		);
	}//end testTheScheduleAnswersTheMemberCountAndTheEntries()

	/**
	 * `GET /api/leave/coverage` answers 404 for a request this caller may not
	 * see, and says nothing about whether it exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function testCoverageAnswersNotFoundForARequestTheCallerMayNotSee(): void {
		$response = $this->leaveController(null)->coverage(leaveRequestId: 'request-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testCoverageAnswersNotFoundForARequestTheCallerMayNotSee()

	/**
	 * `GET /api/leave/coverage` says plainly that a requester with no org unit
	 * has no minimum, rather than answering that coverage is fine.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function testCoverageNamesTheMissingOrgUnitRatherThanAnsweringFine(): void {
		$response = $this->leaveController(['id' => 'request-1', 'employeeId' => 'employee-1'])
			->coverage(leaveRequestId: 'request-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('no-org-unit', $response->getData()['reason']);
		$this->assertFalse($response->getData()['belowMinimum']);
	}//end testCoverageNamesTheMissingOrgUnitRatherThanAnsweringFine()
}//end class
