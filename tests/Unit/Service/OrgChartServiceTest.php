<?php

/**
 * Unit tests for the organisation chart composition and layout.
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
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\OrgChartService;
use OCA\Humaniq\Service\OrgResolutionService;
use PHPUnit\Framework\TestCase;

/**
 * The chart the Organogram page draws.
 */
class OrgChartServiceTest extends TestCase {

	/**
	 * A municipality, two directorates, three teams.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function units(): array {
		return [
			['id' => 'gem', 'name' => 'Gemeente', 'parentUnitId' => null, 'managerId' => 'e-boss', 'active' => true],
			['id' => 'dir-a', 'name' => 'Directie A', 'parentUnitId' => 'gem', 'active' => true],
			['id' => 'dir-b', 'name' => 'Directie B', 'parentUnitId' => 'gem', 'active' => true],
			['id' => 'team-1', 'name' => 'Team 1', 'parentUnitId' => 'dir-a', 'managerId' => 'e-1', 'active' => true],
			['id' => 'team-2', 'name' => 'Team 2', 'parentUnitId' => 'dir-a', 'active' => true],
			['id' => 'team-3', 'name' => 'Team 3', 'parentUnitId' => 'dir-b', 'active' => true],
		];
	}//end units()

	/**
	 * Employees by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function employees(): array {
		return [
			'e-boss' => ['firstName' => 'Anna', 'lastName' => 'Baas'],
			'e-1'    => ['firstName' => 'Sam', 'lastName' => 'Jansen'],
			'e-2'    => ['firstName' => 'Kim', 'lastName' => 'de Vries'],
			'e-3'    => ['firstName' => 'Lars', 'lastName' => 'Visser'],
		];
	}//end employees()

	/**
	 * Placements; one ended before the chart date.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assignments(): array {
		return [
			['employeeId' => 'e-1', 'orgUnitId' => 'team-1', 'role' => 'Teamleider', 'startDate' => '2024-01-01'],
			['employeeId' => 'e-2', 'orgUnitId' => 'team-1', 'role' => 'Adviseur', 'startDate' => '2024-01-01'],
			['employeeId' => 'e-3', 'orgUnitId' => 'team-1', 'role' => 'Adviseur', 'startDate' => '2024-01-01', 'endDate' => '2026-03-31'],
			['employeeId' => 'e-3', 'orgUnitId' => 'team-3', 'role' => 'Adviseur', 'startDate' => '2026-04-01'],
		];
	}//end assignments()

	/**
	 * The chart service.
	 *
	 * @return OrgChartService
	 */
	private function service(): OrgChartService {
		return new OrgChartService(new OrgResolutionService());
	}//end service()

	/**
	 * A three-level tree with managers and headcounts on the date.
	 *
	 * @return void
	 */
	public function testAThreeLevelTreeWithManagersAndHeadcounts(): void {
		$chart = $this->service()->chart(units: $this->units(), assignments: $this->assignments(), employeesById: $this->employees(), date: '2026-06-01');

		self::assertCount(1, $chart['tree']);
		$root = $chart['tree'][0];
		self::assertSame('Gemeente', $root['label']);
		self::assertSame('Anna Baas', $root['managerName']);
		self::assertSame(['Directie A', 'Directie B'], array_column($root['children'], 'label'));
		$team1 = $root['children'][0]['children'][0];
		self::assertSame('Sam Jansen', $team1['managerName']);
		self::assertSame(2, $team1['headcount']);
		self::assertSame(1, $root['children'][1]['children'][0]['headcount']);
		self::assertCount(6, $chart['nodes']);
		self::assertCount(5, $chart['edges']);
	}//end testAThreeLevelTreeWithManagersAndHeadcounts()

	/**
	 * On an earlier date the ended placement still counts in Team 1.
	 *
	 * @return void
	 */
	public function testAnEndedPlacementCountsOnlyBeforeItsEnd(): void {
		$chart = $this->service()->chart(units: $this->units(), assignments: $this->assignments(), employeesById: $this->employees(), date: '2026-01-15');

		self::assertSame(3, $chart['tree'][0]['children'][0]['children'][0]['headcount']);
		self::assertSame(0, $chart['tree'][0]['children'][1]['children'][0]['headcount']);
	}//end testAnEndedPlacementCountsOnlyBeforeItsEnd()

	/**
	 * A unit whose parent is inactive becomes a root, so its branch shows;
	 * inactive units themselves are left out.
	 *
	 * @return void
	 */
	public function testAUnitUnderAnInactiveParentBecomesARoot(): void {
		$units = $this->units();
		$units[2]['active'] = false;

		$chart = $this->service()->chart(units: $units, assignments: [], employeesById: [], date: '2026-06-01');

		self::assertSame(['Gemeente', 'Team 3'], array_column($chart['tree'], 'label'));
		self::assertNotContains('dir-b', array_column($chart['nodes'], 'id'));
	}//end testAUnitUnderAnInactiveParentBecomesARoot()

	/**
	 * People are listed only when asked, and only the readable ones.
	 *
	 * @return void
	 */
	public function testPeopleAreListedOnlyWhenAskedAndReadable(): void {
		$service = $this->service();
		$without = $service->chart(units: $this->units(), assignments: $this->assignments(), employeesById: $this->employees(), date: '2026-06-01');
		self::assertArrayNotHasKey('people', $without['tree'][0]['children'][0]['children'][0]);

		$with = $service->chart(units: $this->units(), assignments: $this->assignments(), employeesById: $this->employees(), date: '2026-06-01', readableEmployeeIds: ['e-2']);
		$team1 = $with['tree'][0]['children'][0]['children'][0];
		self::assertSame([['id' => 'e-2', 'name' => 'Kim de Vries', 'role' => 'Adviseur']], $team1['people']);
		self::assertSame(2, $team1['headcount']);
	}//end testPeopleAreListedOnlyWhenAskedAndReadable()

	/**
	 * A chosen root narrows the chart to its subtree.
	 *
	 * @return void
	 */
	public function testARootNarrowsTheChart(): void {
		$chart = $this->service()->chart(units: $this->units(), assignments: [], employeesById: [], date: '2026-06-01', rootId: 'dir-a');

		self::assertSame(['Directie A'], array_column($chart['tree'], 'label'));
		$ids = array_column($chart['nodes'], 'id');
		sort($ids);
		self::assertSame(['dir-a', 'team-1', 'team-2'], $ids);
	}//end testARootNarrowsTheChart()

	/**
	 * The layout: no two units share a position, children sit one row below
	 * their parent, and a parent is centred over its children.
	 *
	 * @return void
	 */
	public function testTheLayoutPlacesChildrenBelowTheirParent(): void {
		$chart = $this->service()->chart(units: $this->units(), assignments: [], employeesById: [], date: '2026-06-01');
		$byId = array_column($chart['nodes'], null, 'id');

		$positions = array_map(static fn (array $node): string => $node['x'] . ',' . $node['y'], $chart['nodes']);
		self::assertSame(count($positions), count(array_unique($positions)));
		foreach ($chart['edges'] as $edge) {
			self::assertSame($byId[$edge['source']]['y'] + 1, $byId[$edge['target']]['y']);
		}

		self::assertSame(0.5, $byId['dir-a']['x']);
		self::assertSame(2.0, $byId['dir-b']['x']);
		self::assertSame(1.25, $byId['gem']['x']);
		self::assertSame(3, $chart['width']);
		self::assertSame(3, $chart['depth']);
	}//end testTheLayoutPlacesChildrenBelowTheirParent()
}//end class
