<?php

/**
 * The cost of one leave request, per year and per day, as the request page
 * shows it before approval.
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
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Controller\LeaveCostController;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\LeaveCostService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Per year, per day, with the reason named.
 */
class LeaveCostServiceTest extends TestCase {

	/**
	 * The service over one part-timer with a Monday to Wednesday pattern.
	 *
	 * @param array<string, mixed> $calendarAnswer What the calendar reader answers.
	 *
	 * @return LeaveCostService
	 */
	private function service(array $calendarAnswer): LeaveCostService {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(static function (string $schema, array $filters): array {
			return match ($schema) {
				'WorkingPattern' => [['employeeId' => 'emp-part', 'validFrom' => '2026-01-01', 'hoursMonday' => 8, 'hoursTuesday' => 8, 'hoursWednesday' => 8]],
				'LeaveBalance' => [['employeeId' => 'emp-part', 'leaveType' => 'holiday', 'year' => 2026, 'contractHoursPerWeek' => 24]],
				default => [],
			};
		});
		$calendar = $this->createMock(WorkingCalendarReader::class);
		$calendar->expects(self::once())->method('nonWorkingDates')->willReturn($calendarAnswer);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new LeaveCostService($gateway, $calendar, $l10n);
	}//end service()

	/**
	 * Monday to Friday over Easter Monday costs 8 hours for a Monday to
	 * Wednesday worker: Easter Monday is a feestdag, Thursday and Friday free.
	 *
	 * @return void
	 */
	public function testTheCostNamesEachDay(): void {
		$cost = $this->service(['dates' => ['2026-04-06'], 'resolved' => true, 'reason' => null])->costOf(
			['id' => 'lr-1', 'employeeId' => 'emp-part', 'leaveType' => 'holiday', 'startDate' => '2026-04-06', 'endDate' => '2026-04-10']
		);

		self::assertSame(16.0, $cost['hours']);
		self::assertSame('pattern', $cost['basis']);
		self::assertSame([['year' => 2026, 'hours' => 16.0, 'basis' => 'pattern', 'basisLabel' => 'Working pattern and public holidays']], $cost['years']);
		self::assertSame(['feestdag', 'pattern', 'pattern', 'vrije-dag', 'vrije-dag'], array_column($cost['days'], 'reason'));
		self::assertSame('Public holiday', $cost['days'][0]['label']);
		self::assertSame('Day off', $cost['days'][3]['label']);
	}//end testTheCostNamesEachDay()

	/**
	 * An unread calendar is said on the cost.
	 *
	 * @return void
	 */
	public function testAnUnreadCalendarSaysPatternOnly(): void {
		$cost = $this->service(['dates' => null, 'resolved' => false, 'reason' => 'no-working-calendar-service'])->costOf(
			['id' => 'lr-1', 'employeeId' => 'emp-part', 'leaveType' => 'holiday', 'startDate' => '2026-04-06', 'endDate' => '2026-04-07']
		);

		self::assertSame(16.0, $cost['hours']);
		self::assertSame('pattern-only', $cost['basis']);
		self::assertFalse($cost['calendarRead']);
	}//end testAnUnreadCalendarSaysPatternOnly()

	/**
	 * An unreadable request is 404 and costs nothing to compute.
	 *
	 * @return void
	 */
	public function testAnUnreadableRequestIs404(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn(null);
		$service = $this->createMock(LeaveCostService::class);
		$service->expects(self::never())->method('costOf');

		$controller = new LeaveCostController($this->createMock(IRequest::class), $rbac, $service);

		self::assertSame(404, $controller->cost('lr-1')->getStatus());
	}//end testAnUnreadableRequestIs404()

	/**
	 * A readable request answers its cost.
	 *
	 * @return void
	 */
	public function testAReadableRequestAnswersItsCost(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->with('lr-1', 'LeaveRequest')->willReturn(['id' => 'lr-1']);
		$service = $this->createMock(LeaveCostService::class);
		$service->method('costOf')->willReturn(['hours' => 16.0]);

		$response = (new LeaveCostController($this->createMock(IRequest::class), $rbac, $service))->cost('lr-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(16.0, $response->getData()['hours']);
	}//end testAReadableRequestAnswersItsCost()

}//end class
