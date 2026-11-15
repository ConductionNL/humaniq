<?php

/**
 * Leave coverage warning composition tests
 *
 * Pins LeaveCoverageWarning: the requester's org unit and its members are
 * the placements active on the day asked, the unit's minimum and every other
 * request reach LeaveCoverageService, a requester without a unit is said to
 * have none, and what an approval accepts is the message of a warning that
 * takes the unit below its minimum, else nothing.
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\DepartmentLeaveScheduleService;
use OCA\Humaniq\Service\LeaveCoverageService;
use OCA\Humaniq\Service\LeaveCoverageWarning;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RecordStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for composing one request's coverage warning.
 *
 * @covers \OCA\Humaniq\Service\LeaveCoverageWarning
 * @uses \OCA\Humaniq\Service\LeaveCoverageService
 * @uses \OCA\Humaniq\Service\DepartmentLeaveScheduleService
 * @uses \OCA\Humaniq\Service\OrgResolutionService
 */
class LeaveCoverageWarningCompositionTest extends TestCase {

	private const TODAY = '2026-07-20';

	/**
	 * The org assignments in the store.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $assignments = [];

	/**
	 * The service over an in-memory store holding the given unit and requests.
	 *
	 * @param array<string, mixed>             $orgUnit  The OrgUnit `unit-1`.
	 * @param array<int, array<string, mixed>> $requests Every LeaveRequest.
	 *
	 * @return LeaveCoverageWarning
	 */
	private function warnings(array $orgUnit = [], array $requests = []): LeaveCoverageWarning {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(function (string $schema, array $filters): array {
			self::assertSame('OrgAssignment', $schema);
			$key = array_key_first($filters);
			return array_values(array_filter($this->assignments, static fn (array $a): bool => ($a[$key] ?? null) === $filters[$key]));
		});
		$store->method('findObjectData')->willReturnCallback(static fn (string $id, string $schema): ?array => ($id === 'unit-1' && $schema === 'OrgUnit') ? $orgUnit : null);
		$store->method('loadAll')->willReturnCallback(static function (string $schema) use ($requests): array {
			self::assertSame('LeaveRequest', $schema);
			return $requests;
		});

		return new LeaveCoverageWarning(
			gateway: $store,
			coverage: new LeaveCoverageService(new DepartmentLeaveScheduleService()),
			orgResolution: new OrgResolutionService()
		);
	}//end warnings()

	/**
	 * A placement of an employee in a unit.
	 *
	 * @param string      $employeeId The employee.
	 * @param string      $orgUnitId  The unit.
	 * @param string|null $endDate    The last day of the placement.
	 *
	 * @return void
	 */
	private function place(string $employeeId, string $orgUnitId = 'unit-1', ?string $endDate = null): void {
		$this->assignments[] = ['employeeId' => $employeeId, 'orgUnitId' => $orgUnitId, 'startDate' => '2026-01-01', 'endDate' => $endDate];
	}//end place()

	/**
	 * The request about to be approved: emp-3 on Monday 3 August.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(): array {
		return ['id' => 'req-new', 'employeeId' => 'emp-3', 'startDate' => '2026-08-03', 'endDate' => '2026-08-03', 'status' => 'submitted', 'leaveType' => 'holiday'];
	}//end request()

	/**
	 * The unit, its members today and the others away make the warning; a member whose placement ended is not counted.
	 *
	 * @return void
	 */
	public function testTheWarningIsComposedFromTheUnitItsMembersAndTheOtherRequests(): void {
		$this->place('emp-1');
		$this->place('emp-2');
		$this->place('emp-3');
		$this->place('emp-gone', endDate: '2026-06-30');
		$warnings = $this->warnings(
			orgUnit: ['minimumPresentMonday' => 2],
			requests: [['id' => 'req-1', 'employeeId' => 'emp-1', 'startDate' => '2026-08-03', 'endDate' => '2026-08-03', 'status' => 'approved', 'leaveType' => 'holiday']]
		);

		$warning = $warnings->forRequest(request: self::request(), today: new DateTimeImmutable(self::TODAY));

		$this->assertTrue($warning['belowMinimum']);
		$this->assertSame([['date' => '2026-08-03', 'present' => 1, 'minimum' => 2, 'othersAway' => ['emp-1']]], $warning['dates']);
		$this->assertSame($warning['message'], $warnings->acceptedOnApproval(request: self::request(), today: new DateTimeImmutable(self::TODAY)));
		$this->assertStringContainsString('2026-08-03: 1 aanwezig, minimaal 2. Ook afwezig: emp-1.', $warning['message']);
	}//end testTheWarningIsComposedFromTheUnitItsMembersAndTheOtherRequests()

	/**
	 * Enough present means a warning with no dates, and nothing for an approval to accept.
	 *
	 * @return void
	 */
	public function testEnoughPresentIsNoWarningAndNothingAccepted(): void {
		$this->place('emp-1');
		$this->place('emp-2');
		$this->place('emp-3');
		$warnings = $this->warnings(orgUnit: ['minimumPresentMonday' => 2]);

		$this->assertSame(['belowMinimum' => false, 'dates' => [], 'message' => ''], $warnings->forRequest(request: self::request(), today: new DateTimeImmutable(self::TODAY)));
		$this->assertNull($warnings->acceptedOnApproval(request: self::request(), today: new DateTimeImmutable(self::TODAY)));
	}//end testEnoughPresentIsNoWarningAndNothingAccepted()

	/**
	 * A requester with no placement today has no unit and so no minimum, said plainly.
	 *
	 * @return void
	 */
	public function testARequesterWithoutAUnitTodayHasNoMinimum(): void {
		$this->place('emp-3', endDate: '2026-06-30');
		$warnings = $this->warnings(orgUnit: ['minimumPresentMonday' => 5]);

		$this->assertSame(
			['belowMinimum' => false, 'dates' => [], 'message' => '', 'reason' => 'no-org-unit'],
			$warnings->forRequest(request: self::request(), today: new DateTimeImmutable(self::TODAY))
		);
		$this->assertNull($warnings->acceptedOnApproval(request: self::request(), today: new DateTimeImmutable(self::TODAY)));
	}//end testARequesterWithoutAUnitTodayHasNoMinimum()

	/**
	 * A request without an employee has no unit either.
	 *
	 * @return void
	 */
	public function testARequestWithoutAnEmployeeHasNoUnit(): void {
		$this->place('emp-3');

		$this->assertSame('no-org-unit', $this->warnings()->forRequest(request: ['startDate' => '2026-08-03', 'endDate' => '2026-08-03'], today: new DateTimeImmutable(self::TODAY))['reason']);
	}//end testARequestWithoutAnEmployeeHasNoUnit()
}//end class
