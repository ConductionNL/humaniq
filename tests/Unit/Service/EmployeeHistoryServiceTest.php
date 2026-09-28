<?php

/**
 * Unit tests for the employee history (people-employment-history).
 *
 * The pure service runs with the real AbsenceProgression on rows shaped like
 * the register's EmploymentContract, OrgAssignment, CompAdjustment,
 * LeaveRequest, SickLeaveCase and PerformanceReview; the controller runs with
 * its real collaborators' classes mocked by their own method names.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/employee-history/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Controller\EmployeeHistoryController;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\EmployeeHistoryService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the employee history.
 *
 * @spec openspec/specs/employee-history/spec.md
 */
class EmployeeHistoryServiceTest extends TestCase {

	/**
	 * A career reads newest first: second contract, raise, unit move, first contract.
	 *
	 * @return void
	 */
	public function testACareerReadsNewestFirstAndLinksEachRecord(): void {
		$events = $this->service()->historyFor('emp-1', $this->career());
		$kinds = array_map(static fn (array $e): string => $e['kind'] . '@' . $e['start'], $events);

		$this->assertSame(
			['contract-start@2025-02-01', 'pay-change@2024-07-01', 'placement-start@2023-03-01', 'placement-start@2021-01-01', 'contract-start@2021-01-01'],
			$kinds
		);
		$this->assertSame('EmploymentContractDetail', $events[0]['route']);
		$this->assertSame('c-2', $events[0]['id']);
		$this->assertSame('CompAdjustmentDetail', $events[1]['route']);

	}//end testACareerReadsNewestFirstAndLinksEachRecord()

	/**
	 * An unapplied proposal, a rejected leave request, a draft review and
	 * another employee's rows are no history.
	 *
	 * @return void
	 */
	public function testProposalsAndRejectedRequestsDoNotCount(): void {
		$rows = $this->career();
		$rows['CompAdjustment'][] = ['id' => 'ca-2', 'employeeId' => 'emp-1', 'effectiveDate' => '2026-01-01', 'status' => 'proposed', 'appliedAt' => null];
		$rows['LeaveRequest'][] = ['id' => 'lr-1', 'employeeId' => 'emp-1', 'startDate' => '2026-05-01', 'endDate' => '2026-05-02', 'status' => 'rejected', 'leaveType' => 'holiday'];
		$rows['PerformanceReview'][] = ['id' => 'pr-1', 'employeeId' => 'emp-1', 'status' => 'besproken', 'besprokenOp' => '2026-03-01'];
		$rows['EmploymentContract'][] = ['id' => 'c-9', 'employeeId' => 'emp-2', 'startDate' => '2026-06-01'];

		$ids = array_column($this->service()->historyFor('emp-1', $rows), 'id');

		$this->assertNotContains('ca-2', $ids);
		$this->assertNotContains('lr-1', $ids);
		$this->assertNotContains('pr-1', $ids);
		$this->assertNotContains('c-9', $ids);
		$this->assertCount(5, $ids);

	}//end testProposalsAndRejectedRequestsDoNotCount()

	/**
	 * Approved leave, sickness and a final review appear with their period.
	 *
	 * @return void
	 */
	public function testApprovedLeaveSicknessAndFinalReviewAppear(): void {
		$rows = [
			'LeaveRequest' => [['id' => 'lr-2', 'employeeId' => 'emp-1', 'startDate' => '2026-08-03', 'endDate' => '2026-08-14', 'status' => 'approved', 'leaveType' => 'holiday']],
			'SickLeaveCase' => [['id' => 'sl-1', 'employeeId' => 'emp-1', 'firstSickDay' => '2026-02-02', 'recoveredDate' => '2026-02-06']],
			'PerformanceReview' => [['id' => 'pr-2', 'employeeId' => 'emp-1', 'status' => 'vastgesteld', 'besprokenOp' => '2026-06-15', 'rating' => 'goed']],
		];

		$events = $this->service()->historyFor('emp-1', $rows);

		$this->assertSame(['leave', 'review', 'sickness'], array_column($events, 'kind'));
		$this->assertSame('2026-08-14', $events[0]['end']);
		$this->assertSame('SickLeaveCaseDetail', $events[2]['route']);

	}//end testApprovedLeaveSicknessAndFinalReviewAppear()

	/**
	 * A 20-hour schaal 10 and a 16-hour schaal 9 contract: 36 hours, 0.9 FTE, concurrent.
	 *
	 * @return void
	 */
	public function testATeacherWithTwoAppointments(): void {
		$contracts = [
			['id' => 'c-a', 'employeeId' => 'emp-1', 'startDate' => '2024-08-01', 'endDate' => null, 'hoursPerWeek' => 20, 'caoSchaal' => '10', 'cao' => 'cao-po', 'type' => 'permanent'],
			['id' => 'c-b', 'employeeId' => 'emp-1', 'startDate' => '2025-08-01', 'endDate' => null, 'hoursPerWeek' => 16, 'caoSchaal' => '9', 'cao' => 'cao-po', 'type' => 'temporary'],
		];

		$result = $this->service()->activeEmploymentsOn('emp-1', $contracts, new DateTimeImmutable('2026-09-28'), 40.0);

		$this->assertCount(2, $result['contracts']);
		$this->assertSame(36.0, $result['totalHoursPerWeek']);
		$this->assertSame(0.9, $result['totalFte']);
		$this->assertTrue($result['concurrent']);
		$this->assertSame('10', $result['contracts'][0]['caoSchaal']);

	}//end testATeacherWithTwoAppointments()

	/**
	 * A contract that ended last month is not concurrent with the active one.
	 *
	 * @return void
	 */
	public function testAnEndedContractIsNotConcurrent(): void {
		$contracts = [
			['id' => 'c-old', 'employeeId' => 'emp-1', 'startDate' => '2024-01-01', 'endDate' => '2026-08-31', 'hoursPerWeek' => 24],
			['id' => 'c-new', 'employeeId' => 'emp-1', 'startDate' => '2026-09-01', 'endDate' => null, 'hoursPerWeek' => 32],
		];

		$result = $this->service()->activeEmploymentsOn('emp-1', $contracts, new DateTimeImmutable('2026-09-28'), 40.0);

		$this->assertSame(['c-new'], array_column($result['contracts'], 'id'));
		$this->assertFalse($result['concurrent']);

	}//end testAnEndedContractIsNotConcurrent()

	/**
	 * A manager who may not read the employee gets 404 and no event, and
	 * nothing is loaded.
	 *
	 * @return void
	 */
	public function testAManagerOutsideTheTeamGetsNothing(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn(null);
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->expects($this->never())->method('findFiltered');

		$controller = new EmployeeHistoryController($this->createMock(IRequest::class), $gateway, $this->service(), $rbac);
		$history = $controller->history('emp-other');
		$employments = $controller->employments('emp-other');

		$this->assertSame(404, $history->getStatus());
		$this->assertArrayNotHasKey('events', $history->getData());
		$this->assertSame(404, $employments->getStatus());

	}//end testAManagerOutsideTheTeamGetsNothing()

	/**
	 * A source row the caller may not read is left out of the history.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceRowIsDropped(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(
			static fn (string $id, string $schema): ?array => ($id === 'sl-hidden') ? null : ['id' => $id]
		);
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters): array {
				if ($schema === 'SickLeaveCase') {
					return [['id' => 'sl-hidden', 'employeeId' => 'emp-1', 'firstSickDay' => '2026-02-02']];
				}

				if ($schema === 'EmploymentContract') {
					return [['id' => 'c-1', 'employeeId' => 'emp-1', 'startDate' => '2021-01-01']];
				}

				return [];
			}
		);

		$controller = new EmployeeHistoryController($this->createMock(IRequest::class), $gateway, $this->service(), $rbac);
		$events = $controller->history('emp-1')->getData()['events'];

		$this->assertSame(['c-1'], array_column($events, 'id'));

	}//end testAnUnreadableSourceRowIsDropped()

	/**
	 * The employments answer names its full-time basis and shows only this
	 * year's leave balances.
	 *
	 * @return void
	 */
	public function testTheEmploymentsAnswerNamesItsBasisAndThisYearsLeave(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(static fn (string $id): array => ['id' => $id]);
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(
			static function (string $schema): array {
				if ($schema === 'LeaveBalance') {
					return [['id' => 'lb-26', 'employeeId' => 'emp-1', 'year' => 2026, 'leaveType' => 'holiday'], ['id' => 'lb-25', 'employeeId' => 'emp-1', 'year' => 2025, 'leaveType' => 'holiday']];
				}

				return [['id' => 'c-1', 'employeeId' => 'emp-1', 'startDate' => '2021-01-01', 'hoursPerWeek' => 36]];
			}
		);

		$controller = new EmployeeHistoryController($this->createMock(IRequest::class), $gateway, $this->service(), $rbac);
		$data = $controller->employments('emp-1', '2026-09-28')->getData();

		$this->assertSame(40.0, $data['fullTimeHoursPerWeek']);
		$this->assertNotEmpty($data['normSource']);
		$this->assertSame(['lb-26'], array_column($data['leaveBalances'], 'id'));
		$this->assertSame(0.9, $data['totalFte']);

	}//end testTheEmploymentsAnswerNamesItsBasisAndThisYearsLeave()

	/**
	 * The service under test.
	 *
	 * @return EmployeeHistoryService
	 */
	private function service(): EmployeeHistoryService {
		return new EmployeeHistoryService(new AbsenceProgression());

	}//end service()

	/**
	 * First contract 2021, unit move 2023, applied raise 2024, second contract 2025.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function career(): array {
		return [
			'EmploymentContract' => [
				['id' => 'c-1', 'employeeId' => 'emp-1', 'type' => 'permanent', 'startDate' => '2021-01-01', 'endDate' => null],
				['id' => 'c-2', 'employeeId' => 'emp-1', 'type' => 'temporary', 'startDate' => '2025-02-01', 'endDate' => null],
			],
			'OrgAssignment' => [
				['id' => 'oa-1', 'employeeId' => 'emp-1', 'role' => 'member', 'startDate' => '2021-01-01', 'endDate' => null],
				['id' => 'oa-2', 'employeeId' => 'emp-1', 'role' => 'member', 'startDate' => '2023-03-01', 'endDate' => null],
			],
			'CompAdjustment' => [
				['id' => 'ca-1', 'employeeId' => 'emp-1', 'effectiveDate' => '2024-07-01', 'status' => 'effective', 'appliedAt' => '2024-06-20T10:00:00Z', 'currentSalary' => 3800, 'proposedSalary' => 4000],
			],
		];

	}//end career()

}//end class
