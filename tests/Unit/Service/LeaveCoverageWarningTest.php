<?php

/**
 * Leave coverage warning tests
 *
 * Pins LeaveCoverageService::warning over a range of days: which days are
 * named, that the requester's own earlier version of the request is not
 * counted twice, that the requester is never listed as away beside
 * themselves, and that a request without a valid date range warns about
 * nothing.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\DepartmentLeaveScheduleService;
use OCA\Humaniq\Service\LeaveCoverageService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the coverage warning over several days.
 */
class LeaveCoverageWarningTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var LeaveCoverageService
	 */
	private LeaveCoverageService $coverage;

	/**
	 * Build the service over the real schedule.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->coverage = new LeaveCoverageService(new DepartmentLeaveScheduleService());
	}//end setUp()

	/**
	 * Over Monday to Wednesday only the days with a minimum and too few
	 * present are named, in order, each with the others away; the
	 * requester's earlier version of the same request is replaced, not added.
	 *
	 * @return void
	 */
	public function testAMultiDayRequestNamesEachShortDay(): void {
		$warning = $this->coverage->warning(
			request: ['id' => 'req-new', 'employeeId' => 'emp-3', 'startDate' => '2026-08-03', 'endDate' => '2026-08-05'],
			orgUnit: ['minimumPresentMonday' => 2, 'minimumPresentWednesday' => 1],
			memberEmployeeIds: ['emp-1', 'emp-2', 'emp-3'],
			otherRequests: [
				['id' => 'req-new', 'employeeId' => 'emp-3', 'startDate' => '2026-07-01', 'endDate' => '2026-07-01', 'status' => 'approved', 'leaveType' => 'holiday'],
				['id' => 'req-1', 'employeeId' => 'emp-1', 'startDate' => '2026-08-03', 'endDate' => '2026-08-05', 'status' => 'approved', 'leaveType' => 'holiday'],
				['id' => 'req-2', 'employeeId' => 'emp-2', 'startDate' => '2026-08-05', 'endDate' => '2026-08-05', 'status' => 'submitted', 'leaveType' => 'holiday'],
			]
		);

		$this->assertTrue($warning['belowMinimum']);
		$this->assertSame(
			[
				['date' => '2026-08-03', 'present' => 1, 'minimum' => 2, 'othersAway' => ['emp-1']],
				['date' => '2026-08-05', 'present' => 0, 'minimum' => 1, 'othersAway' => ['emp-1', 'emp-2']],
			],
			$warning['dates']
		);
		$this->assertSame(
			'Goedkeuren laat de afdeling onder de bezetting die is afgesproken. 2026-08-03: 1 aanwezig, minimaal 2. Ook afwezig: emp-1. 2026-08-05: 0 aanwezig, minimaal 1. Ook afwezig: emp-1, emp-2. Goedkeuren kan wel; wat u hier leest wordt op de aanvraag vastgelegd.',
			$warning['message']
		);
	}//end testAMultiDayRequestNamesEachShortDay()

	/**
	 * A request with a missing or reversed date range warns about nothing.
	 *
	 * @return void
	 */
	public function testARequestWithoutAValidRangeWarnsAboutNothing(): void {
		$none = ['belowMinimum' => false, 'dates' => [], 'message' => ''];
		$orgUnit = ['minimumPresentMonday' => 5];
		foreach ([['startDate' => '2026-08-05', 'endDate' => '2026-08-03'], ['startDate' => '2026-08-03'], ['endDate' => '2026-08-03']] as $dates) {
			$warning = $this->coverage->warning(
				request: (['id' => 'req-new', 'employeeId' => 'emp-3'] + $dates),
				orgUnit: $orgUnit,
				memberEmployeeIds: ['emp-3'],
				otherRequests: []
			);
			$this->assertSame($none, $warning);
		}
	}//end testARequestWithoutAValidRangeWarnsAboutNothing()
}//end class
