<?php

/**
 * Unit tests for formation occupancy (people-formation-positions).
 *
 * Drives the pure count with the real AbsenceProgression and records shaped
 * like the register's Formatieplaats, EmploymentContract, LeaveRequest and
 * SickLeaveCase, and the controller with its real collaborators' classes
 * mocked by their own method names.
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
 * @spec openspec/specs/formation-positions/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Controller\FormationController;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\FormationOccupancyService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for formation occupancy.
 *
 * @spec openspec/specs/formation-positions/spec.md
 */
class FormationOccupancyServiceTest extends TestCase {

	/**
	 * A Monday.
	 *
	 * @var string
	 */
	private const MONDAY = '2026-09-21';

	/**
	 * A Friday.
	 *
	 * @var string
	 */
	private const FRIDAY = '2026-09-25';

	/**
	 * A new place with no contract is fully vacant.
	 *
	 * @return void
	 */
	public function testANewPlaceIsFullyVacant(): void {
		$result = $this->tally([$this->place('p1', 4.0)], [], [], []);

		$this->assertSame(4.0, $result['places'][0]['budgetedFte']);
		$this->assertSame(0.0, $result['places'][0]['filledFte']);
		$this->assertSame(4.0, $result['places'][0]['vacantFte']);

	}//end testANewPlaceIsFullyVacant()

	/**
	 * A 4.0 place filled by contracts of 3.6 FTE shows 0.4 vacant.
	 *
	 * @return void
	 */
	public function testAPartlyFilledPlaceShowsItsVacancy(): void {
		$contracts = [
			$this->contract('e1', 'p1', 40),
			$this->contract('e2', 'p1', 40),
			$this->contract('e3', 'p1', 32),
			$this->contract('e4', 'p1', 32),
		];

		$result = $this->tally([$this->place('p1', 4.0)], $contracts, [], []);

		$this->assertSame(3.6, $result['places'][0]['filledFte']);
		$this->assertSame(0.4, $result['places'][0]['vacantFte']);
		$this->assertFalse($result['places'][0]['overfilled']);

	}//end testAPartlyFilledPlaceShowsItsVacancy()

	/**
	 * Two 0.8 contracts on a 1.0 place: filled 1.6, vacant 0, overfilled.
	 *
	 * @return void
	 */
	public function testAnOverfilledPlaceIsShownNotRefused(): void {
		$contracts = [$this->contract('e1', 'p1', 32), $this->contract('e2', 'p1', 32)];

		$result = $this->tally([$this->place('p1', 1.0)], $contracts, [], []);

		$this->assertSame(1.6, $result['places'][0]['filledFte']);
		$this->assertSame(0.0, $result['places'][0]['vacantFte']);
		$this->assertTrue($result['places'][0]['overfilled']);

	}//end testAnOverfilledPlaceIsShownNotRefused()

	/**
	 * A contract that ended, or names another place, does not fill this one.
	 *
	 * @return void
	 */
	public function testOnlyActiveContractsOnThePlaceCount(): void {
		$ended = $this->contract('e1', 'p1', 40);
		$ended['endDate'] = '2026-06-30';
		$contracts = [$ended, $this->contract('e2', 'p2', 40), $this->contract('e3', 'p1', 20)];

		$result = $this->tally([$this->place('p1', 2.0)], $contracts, [], []);

		$this->assertSame(0.5, $result['places'][0]['filledFte']);

	}//end testOnlyActiveContractsOnThePlaceCount()

	/**
	 * The tender scenario: 4.6 filled, parental leave two of five days for a
	 * 1.0 occupant, and ten weeks of sickness at 50 percent for a 0.8
	 * occupant, averaged over a full week: net 3.8.
	 *
	 * @return void
	 */
	public function testParentalLeaveAndLongSicknessLowerTheNetFigure(): void {
		$places = [$this->place('p1', 4.0), $this->place('p2', 1.0)];
		$contracts = [
			$this->contract('parent', 'p1', 40),
			$this->contract('sick', 'p1', 32),
			$this->contract('e3', 'p1', 40),
			$this->contract('e4', 'p1', 32),
			$this->contract('lead', 'p2', 40),
		];
		$leave = [
			$this->leave('parent', 'parental', '2026-09-21', '2026-09-21'),
			$this->leave('parent', 'parental', '2026-09-22', '2026-09-22'),
		];
		$sick = [[
			'employeeId' => 'sick',
			'firstSickDay' => '2026-07-13',
			'recoveredDate' => null,
			'absenceProgression' => [['effectiveFrom' => '2026-07-13', 'absencePercentage' => 50]],
		]];

		$result = $this->tally($places, $contracts, $leave, $sick);

		$this->assertSame(4.6, $result['total']['filledFte']);
		$this->assertSame(3.8, $result['total']['netFte']);
		$this->assertSame(5.0, $result['total']['budgetedFte']);
		$this->assertSame(0.4, $result['total']['vacantFte']);

	}//end testParentalLeaveAndLongSicknessLowerTheNetFigure()

	/**
	 * Two weeks of sickness is below the default six-week threshold.
	 *
	 * @return void
	 */
	public function testAShortSicknessDoesNotCount(): void {
		$sick = [['employeeId' => 'e1', 'firstSickDay' => '2026-09-14', 'recoveredDate' => null]];

		$result = $this->tally([$this->place('p1', 1.0)], [$this->contract('e1', 'p1', 40)], [], $sick);

		$this->assertSame(1.0, $result['places'][0]['netFte']);

	}//end testAShortSicknessDoesNotCount()

	/**
	 * Holiday leave is not in the net-FTE list and an unapproved parental
	 * request does not count.
	 *
	 * @return void
	 */
	public function testOnlyApprovedLeaveOfAListedTypeCounts(): void {
		$leave = [
			$this->leave('e1', 'holiday', self::MONDAY, self::FRIDAY),
			array_merge($this->leave('e1', 'parental', self::MONDAY, self::FRIDAY), ['status' => 'submitted']),
		];

		$result = $this->tally([$this->place('p1', 1.0)], [$this->contract('e1', 'p1', 40)], $leave, []);

		$this->assertSame(1.0, $result['places'][0]['netFte']);

	}//end testOnlyApprovedLeaveOfAListedTypeCounts()

	/**
	 * The endpoint answers 404 for a unit the caller cannot read, before
	 * any record is loaded.
	 *
	 * @return void
	 */
	public function testTheEndpointRefusesAnUnreadableUnit(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn(null);
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->expects($this->never())->method('loadAll');
		$gateway->expects($this->never())->method('findFiltered');

		$controller = new FormationController(
			$this->createMock(IRequest::class),
			$gateway,
			new FormationOccupancyService(new AbsenceProgression()),
			$rbac,
			$this->createMock(SettingsService::class)
		);

		$this->assertSame(404, $controller->occupancy(orgUnitId: 'unit-of-another-team')->getStatus());
		$this->assertSame(404, $controller->occupancy(formatieplaatsId: 'place-of-another-team')->getStatus());
		$this->assertSame(404, $controller->occupancy()->getStatus());

	}//end testTheEndpointRefusesAnUnreadableUnit()

	/**
	 * The endpoint counts a readable unit's active places only, and answers
	 * FTE figures without naming who fills a place.
	 *
	 * @return void
	 */
	public function testTheEndpointCountsAReadableUnitsActivePlaces(): void {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn(['id' => 'u1', 'name' => 'Team Burgerzaken']);
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$retired = array_merge($this->place('p2', 1.0), ['status' => 'opgeheven']);
		$gateway->method('findFiltered')->with('Formatieplaats', ['orgUnitId' => 'u1'])->willReturn([$this->place('p1', 2.0), $retired]);
		$gateway->method('loadAll')->willReturnCallback(
			fn (string $schema): array => ($schema === 'EmploymentContract') ? [$this->contract('e1', 'p1', 40)] : []
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFormationNetFteLeaveTypes')->willReturn(['parental', 'zwangerschap']);
		$settings->method('getFormationLongTermSickWeeks')->willReturn(6);

		$controller = new FormationController(
			$this->createMock(IRequest::class),
			$gateway,
			new FormationOccupancyService(new AbsenceProgression()),
			$rbac,
			$settings
		);
		$response = $controller->occupancy(orgUnitId: 'u1', from: self::MONDAY, to: self::FRIDAY);
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $data['places']);
		$this->assertSame(1.0, $data['places'][0]['filledFte']);
		$this->assertSame(1.0, $data['total']['vacantFte']);
		$this->assertStringNotContainsString('e1', (string)json_encode($data));

	}//end testTheEndpointCountsAReadableUnitsActivePlaces()

	/**
	 * Count over the Monday to Friday week with the default settings.
	 *
	 * @param list<array<string, mixed>> $places    Places.
	 * @param list<array<string, mixed>> $contracts Contracts.
	 * @param list<array<string, mixed>> $leave     Leave requests.
	 * @param list<array<string, mixed>> $sick      Sickness cases.
	 *
	 * @return array{places: list<array<string, mixed>>, total: array<string, mixed>}
	 */
	private function tally(array $places, array $contracts, array $leave, array $sick): array {
		return (new FormationOccupancyService(new AbsenceProgression()))->occupancy(
			places: $places,
			contracts: $contracts,
			leaveRequests: $leave,
			sickCases: $sick,
			from: new DateTimeImmutable(self::MONDAY),
			to: new DateTimeImmutable(self::FRIDAY),
			netLeaveTypes: ['parental', 'zwangerschap'],
			longTermSickWeeks: 6,
			fullTimeHoursWeek: 40.0
		);

	}//end tally()

	/**
	 * A Formatieplaats record.
	 *
	 * @param string $id  Id.
	 * @param float  $fte Budgeted FTE.
	 *
	 * @return array<string, mixed>
	 */
	private function place(string $id, float $fte): array {
		return ['id' => $id, 'orgUnitId' => 'u1', 'title' => 'Plaats ' . $id, 'budgetedFte' => $fte, 'validFrom' => '2026-01-01', 'status' => 'actief'];

	}//end place()

	/**
	 * An EmploymentContract record.
	 *
	 * @param string $employeeId Employee.
	 * @param string $placeId    Formatieplaats.
	 * @param int    $hours      Hours per week.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(string $employeeId, string $placeId, int $hours): array {
		return ['employeeId' => $employeeId, 'formatieplaatsId' => $placeId, 'hoursPerWeek' => $hours, 'startDate' => '2025-01-01', 'endDate' => null];

	}//end contract()

	/**
	 * An approved LeaveRequest record.
	 *
	 * @param string $employeeId Employee.
	 * @param string $type       Leave type code.
	 * @param string $start      First day.
	 * @param string $end        Last day.
	 *
	 * @return array<string, mixed>
	 */
	private function leave(string $employeeId, string $type, string $start, string $end): array {
		return ['employeeId' => $employeeId, 'leaveType' => $type, 'startDate' => $start, 'endDate' => $end, 'status' => 'approved'];

	}//end leave()

}//end class
