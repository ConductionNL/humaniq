<?php

/**
 * Unit tests for AttendanceTodayService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/time-attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Controller\AttendanceClockController;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\AttendanceClockService;
use OCA\Humaniq\Service\AttendanceTodayService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\UnitMembership;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Who is present, late, away or not clocked in today (REQ-TA-007).
 *
 * @spec openspec/specs/time-attendance/spec.md#requirement-a-manager-shall-see-who-is-present-absent-or-late-today-req-ta-007
 */
#[CoversClass(AttendanceTodayService::class)]
#[CoversClass(AttendanceClockController::class)]
#[UsesClass(UnitMembership::class)]
#[UsesClass(AttendanceClockService::class)]
final class AttendanceTodayServiceTest extends TestCase {

	/**
	 * A team of four in Team A (led by boss), and one person in Team B.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function rows(): array {
		$placed = static fn (string $employeeId, string $unitId): array => ['employeeId' => $employeeId, 'orgUnitId' => $unitId, 'startDate' => '2026-01-01'];
		$person = static fn (string $id, string $first): array => ['id' => $id, 'firstName' => $first, 'lastName' => 'Test'];

		return [
			'OrgUnit' => [['id' => 'u-a', 'name' => 'Team A', 'managerId' => 'emp-boss'], ['id' => 'u-b', 'name' => 'Team B', 'managerId' => 'emp-other']],
			'Employee' => [['id' => 'emp-boss', 'nextcloudUserId' => 'boss'], $person('e-early', 'Eva'), $person('e-late', 'Lars'), $person('e-leave', 'Lea'), $person('e-none', 'Nina'), $person('e-other', 'Otto')],
			'OrgAssignment' => [$placed('e-early', 'u-a'), $placed('e-late', 'u-a'), $placed('e-leave', 'u-a'), $placed('e-none', 'u-a'), $placed('e-other', 'u-b')],
			'AttendanceRecord' => [
				['employeeId' => 'e-early', 'date' => '2026-03-02', 'clockIn' => '2026-03-02T08:00:00+01:00'],
				['employeeId' => 'e-late', 'date' => '2026-03-02', 'clockIn' => '2026-03-02T09:15:00+01:00'],
				['employeeId' => 'e-none', 'date' => '2026-03-01', 'clockIn' => '2026-03-01T08:00:00+01:00'],
			],
			'LeaveRequest' => [
				['employeeId' => 'e-leave', 'status' => 'approved', 'startDate' => '2026-03-02', 'endDate' => '2026-03-06'],
				['employeeId' => 'e-none', 'status' => 'submitted', 'startDate' => '2026-03-02', 'endDate' => '2026-03-02'],
			],
			'SickLeaveCase' => [],
		];
	}//end rows()

	/**
	 * A service at 09:30 on 2 March for a full reader or not.
	 *
	 * @param bool                            $fullReader Whether the caller is HR or an accountant.
	 * @param list<array<string, mixed>>|null $sick       Sick cases, or null for none.
	 *
	 * @return AttendanceTodayService
	 */
	private function service(bool $fullReader = false, ?array $sick = null): AttendanceTodayService {
		$rows = self::rows();
		if ($sick !== null) {
			$rows['SickLeaveCase'] = $sick;
		}

		$analytics = $this->createMock(AnalyticsService::class);
		$analytics->method('rowsFor')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$access = $this->createMock(AnalyticsAccess::class);
		$access->method('activeAdministration')->willReturn('adm-1');
		$access->method('fullReaderAdministration')->willReturn($fullReader === true ? 'adm-1' : null);
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->with('RosterAssignment', ['date' => '2026-03-02'])->willReturn(
			[['employeeId' => 'e-late', 'date' => '2026-03-02', 'plannedStart' => '2026-03-02T09:00:00+01:00'], ['employeeId' => 'e-early', 'date' => '2026-03-02', 'plannedStart' => '2026-03-02T08:30:00+01:00']]
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-03-02T09:30:00+01:00'));

		return new AttendanceTodayService($analytics, new UnitMembership(), $access, $store, $time);
	}//end service()

	/**
	 * Scenario: a team at 09:30 reads as present, late, away and not clocked in.
	 *
	 * @return void
	 */
	public function testATeamAtHalfPastNine(): void {
		$view = $this->service()->today('boss');

		self::assertSame('2026-03-02', $view['date']);
		self::assertSame(['e-late' => 'late', 'e-none' => 'not-clocked-in', 'e-leave' => 'away', 'e-early' => 'present'], array_column($view['people'], 'status', 'id'), 'Late first, present last.');
		self::assertSame(['late' => 1, 'not-clocked-in' => 1, 'away' => 1, 'present' => 1], $view['counts']);
		self::assertSame('2026-03-02T09:00:00+01:00', $view['people'][0]['plannedStart']);
	}//end testATeamAtHalfPastNine()

	/**
	 * Scenario: another team stays hidden; HR sees everyone; an open sick case is away.
	 *
	 * @return void
	 */
	public function testAnotherTeamStaysHidden(): void {
		self::assertNotContains('e-other', array_column($this->service()->today('boss')['people'], 'id'));
		self::assertSame([], $this->service()->today('nobody')['people'], 'Someone who leads nothing sees no one.');
		self::assertContains('e-other', array_column($this->service(true)->today('hr')['people'], 'id'));

		$sick = $this->service(false, [['employeeId' => 'e-none', 'status' => 'gemeld', 'firstSickDay' => '2026-02-27']]);
		self::assertSame('away', array_column($sick->today('boss')['people'], 'status', 'id')['e-none']);
	}//end testAnotherTeamStaysHidden()

	/**
	 * The controller: signed out 401, without the service 403, else the view.
	 *
	 * @return void
	 */
	public function testTheControllerAnswers(): void {
		$session = function (?string $uid): IUserSession {
			$session = $this->createMock(IUserSession::class);
			$user = null;
			if ($uid !== null) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
			}

			$session->method('getUser')->willReturn($user);
			return $session;
		};
		$clock = new AttendanceClockService($this->createMock(RecordStoreInterface::class), $this->createMock(\Psr\Container\ContainerInterface::class), $this->createMock(\OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface::class), $this->createMock(ITimeFactory::class), $this->createMock(\Psr\Log\LoggerInterface::class));

		self::assertSame(401, (new AttendanceClockController($this->createMock(IRequest::class), $clock, $session(null), $this->service()))->today()->getStatus());
		self::assertSame(403, (new AttendanceClockController($this->createMock(IRequest::class), $clock, $session('boss')))->today()->getStatus());
		self::assertCount(4, (new AttendanceClockController($this->createMock(IRequest::class), $clock, $session('boss'), $this->service()))->today()->getData()['people']);
	}//end testTheControllerAnswers()
}//end class
