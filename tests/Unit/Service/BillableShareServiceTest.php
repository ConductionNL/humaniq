<?php

/**
 * Unit tests for BillableShareService.
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/humaniq-dashboard-steering-indicators/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\BillableShareService;
use OCA\Humaniq\Service\UnitMembership;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The billable share per team and per person (REQ-DSI-010).
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/humaniq-dashboard-steering-indicators/spec.md#requirement-the-billable-share-shall-be-readable-per-team-and-per-person-req-dsi-010
 */
#[CoversClass(BillableShareService::class)]
#[UsesClass(UnitMembership::class)]
final class BillableShareServiceTest extends TestCase {

	/**
	 * October: Vergunningen 84 of 120 billable over two people, Burgerzaken 60
	 * of 100, Belastingen 10 of 10; and hours outside October or unapproved.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function rows(): array {
		$placed = static fn (string $employeeId, string $unitId): array => ['employeeId' => $employeeId, 'orgUnitId' => $unitId, 'startDate' => '2026-01-01'];
		$sheet = static fn (string $id, string $employeeId, float $hours, bool $billable, string $status = 'approved', string $period = '2026-10'): array => ['id' => $id, 'employeeId' => $employeeId, 'period' => $period, 'status' => $status, 'hours' => $hours, 'billable' => $billable];

		return [
			'OrgUnit' => [
				['id' => 'u-verg', 'name' => 'Team Vergunningen', 'managerId' => 'emp-boss'],
				['id' => 'u-burg', 'name' => 'Team Burgerzaken', 'managerId' => 'emp-boss'],
				['id' => 'u-bel', 'name' => 'Team Belastingen', 'managerId' => 'emp-other'],
			],
			'Employee' => [
				['id' => 'emp-boss', 'nextcloudUserId' => 'boss', 'firstName' => 'Bea', 'lastName' => 'Baas'],
				['id' => 'emp-a', 'firstName' => 'Anna', 'lastName' => 'Aa', 'employeeNumber' => 'E1'],
				['id' => 'emp-b', 'firstName' => 'Bram', 'lastName' => 'Bee', 'employeeNumber' => 'E2'],
				['id' => 'emp-c', 'firstName' => 'Cees', 'lastName' => 'Cee'],
				['id' => 'emp-d', 'firstName' => 'Dirk', 'lastName' => 'Dee'],
			],
			'OrgAssignment' => [$placed('emp-a', 'u-verg'), $placed('emp-b', 'u-verg'), $placed('emp-c', 'u-burg'), $placed('emp-d', 'u-bel')],
			'Timesheet' => [
				// Anna books per entry: 44 of 80 billable.
				$sheet('ts-a', 'emp-a', 80.0, false),
				// Bram has no entries: the timesheet's own 40 hours, billable as a whole.
				$sheet('ts-b', 'emp-b', 40.0, true),
				$sheet('ts-b-draft', 'emp-b', 99.0, true, 'submitted'),
				$sheet('ts-b-sep', 'emp-b', 99.0, true, 'approved', '2026-09'),
				$sheet('ts-c', 'emp-c', 100.0, false),
				$sheet('ts-d', 'emp-d', 10.0, true),
			],
			'TimeEntry' => [
				['timesheetId' => 'ts-a', 'hours' => 44.0, 'billable' => true],
				['timesheetId' => 'ts-a', 'hours' => 36.0, 'billable' => false],
				['timesheetId' => 'ts-c', 'hours' => 60.0, 'billable' => true],
				['timesheetId' => 'ts-c', 'hours' => 40.0, 'billable' => false],
			],
		];
	}//end rows()

	/**
	 * A service for a caller with or without the full-reader role.
	 *
	 * @param bool                      $fullReader Whether the caller is HR or an accountant.
	 * @param array<string, mixed>|null $unitReader What unitReader answers.
	 *
	 * @return BillableShareService
	 */
	private function service(bool $fullReader, ?array $unitReader = null): BillableShareService {
		$rows = self::rows();
		$analytics = $this->createMock(AnalyticsService::class);
		$analytics->method('rowsFor')->willReturnCallback(
			static function (string $schema, string $administrationId) use ($rows): array {
				self::assertSame('adm-1', $administrationId);
				return ($rows[$schema] ?? []);
			}
		);

		$access = $this->createMock(AnalyticsAccess::class);
		$access->method('activeAdministration')->willReturn('adm-1');
		$access->method('fullReaderAdministration')->willReturn($fullReader === true ? 'adm-1' : null);
		$access->method('unitReader')->willReturn($unitReader);

		return new BillableShareService($analytics, new UnitMembership(), $access);
	}//end service()

	/**
	 * Scenario: a manager compares the teams they lead.
	 *
	 * @return void
	 */
	public function testAManagerComparesTheTeamsTheyLead(): void {
		$result = $this->service(false)->perUnit('boss', '2026-10');

		self::assertSame(
			[
				['id' => 'u-burg', 'name' => 'Team Burgerzaken', 'people' => 1, 'approvedHours' => 100.0, 'billableHours' => 60.0, 'billableShare' => 60.0],
				['id' => 'u-verg', 'name' => 'Team Vergunningen', 'people' => 2, 'approvedHours' => 120.0, 'billableHours' => 84.0, 'billableShare' => 70.0],
			],
			$result['units']
		);
		self::assertSame('2026-10', $result['period']);
	}//end testAManagerComparesTheTeamsTheyLead()

	/**
	 * HR reads every unit of the administration.
	 *
	 * @return void
	 */
	public function testHrReadsEveryUnit(): void {
		$units = $this->service(true)->perUnit('hr-user', '2026-10')['units'];

		self::assertSame(['u-bel', 'u-burg', 'u-verg'], array_column($units, 'id'));
		self::assertSame(100.0, $units[0]['billableShare']);
	}//end testHrReadsEveryUnit()

	/**
	 * Scenario: the share per person in one team.
	 *
	 * @return void
	 */
	public function testTheSharePerPersonInOneTeam(): void {
		$result = $this->service(false, ['administrationId' => 'adm-1', 'minimumMembers' => 5])->perEmployee('boss', 'u-verg', '2026-10');

		self::assertSame('Team Vergunningen', $result['name']);
		self::assertSame(
			[
				['id' => 'emp-a', 'name' => 'Anna Aa', 'employeeNumber' => 'E1', 'approvedHours' => 80.0, 'billableHours' => 44.0, 'billableShare' => 55.0],
				['id' => 'emp-b', 'name' => 'Bram Bee', 'employeeNumber' => 'E2', 'approvedHours' => 40.0, 'billableHours' => 40.0, 'billableShare' => 100.0],
			],
			$result['people']
		);
	}//end testTheSharePerPersonInOneTeam()

	/**
	 * Scenario: another team stays closed; a bad period is refused; no hours is null, not 0%.
	 *
	 * @return void
	 */
	public function testAnotherTeamStaysClosedAndNoHoursIsNotZero(): void {
		self::assertNull($this->service(false, null)->perEmployee('boss', 'u-bel', '2026-10'));
		self::assertNull($this->service(true, ['administrationId' => 'adm-1', 'minimumMembers' => 0])->perEmployee('hr-user', '', '2026-10'));

		$november = $this->service(false)->perUnit('boss', '2026-11')['units'];
		self::assertSame([null, null], array_column($november, 'billableShare'));

		$this->expectException(InvalidArgumentException::class);
		$this->service(false)->perUnit('boss', '2026-13');
	}//end testAnotherTeamStaysClosedAndNoHoursIsNotZero()
}//end class
