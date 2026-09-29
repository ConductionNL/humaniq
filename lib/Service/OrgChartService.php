<?php

/**
 * Humaniq OrgChartService
 *
 * The organisation chart on a date (people-org-chart-view D1, D2): the tree of
 * active units under a root, each with its manager's name and the number of
 * people placed in it on that date, optionally the placed people, and a
 * top-down layout for the drawn chart. A unit whose parent is missing or
 * inactive becomes a root, so a broken link shows rather than hides a branch.
 *
 * Pure composition over rows the caller loads; which people the caller may
 * see is the caller's decision (`readableEmployeeIds`).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

/**
 * Composes and lays out the organisation chart.
 *
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */
final class OrgChartService {

	/**
	 * Constructor.
	 *
	 * @param OrgResolutionService $resolution Whether a placement is live on a date.
	 */
	public function __construct(
		private readonly OrgResolutionService $resolution,
	) {
	}//end __construct()

	/**
	 * The chart: `tree` for the list view, `nodes` and `edges` for the drawn
	 * chart (x is the position among the leaves, y the depth), and its
	 * `width` in leaves and `depth` in levels.
	 *
	 * @param array<int, array<string, mixed>>    $units               OrgUnit rows.
	 * @param array<int, array<string, mixed>>    $assignments         OrgAssignment rows.
	 * @param array<string, array<string, mixed>> $employeesById       Employee rows by id, for names.
	 * @param string                              $date                The day (Y-m-d).
	 * @param string|null                         $rootId              Only this unit's subtree.
	 * @param array<int, string>|null             $readableEmployeeIds List these people; null lists none.
	 *
	 * @return array{tree: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, edges: array<int, array<string, string>>, width: int, depth: int}
	 *
	 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
	 */
	public function chart(array $units, array $assignments, array $employeesById, string $date, ?string $rootId=null, ?array $readableEmployeeIds=null): array {
		$active = [];
		foreach ($units as $unit) {
			$unitId = (string)($unit['id'] ?? '');
			if ($unitId !== '' && ($unit['active'] ?? true) !== false) {
				$active[$unitId] = $unit;
			}
		}

		$children = [];
		$roots = [];
		foreach ($active as $unitId => $unit) {
			$parentId = (string)($unit['parentUnitId'] ?? '');
			if ($parentId !== '' && isset($active[$parentId]) === true && $parentId !== $unitId) {
				$children[$parentId][] = $unitId;
				continue;
			}

			$roots[] = $unitId;
		}

		if ($rootId !== null && $rootId !== '') {
			$roots = [];
			if (isset($active[$rootId]) === true) {
				$roots = [$rootId];
			}
		}

		$placed = $this->placedOn(assignments: $assignments, date: $date);
		$context = [
			'units'     => $active,
			'children'  => $children,
			'placed'    => $placed,
			'employees' => $employeesById,
			'readable'  => ($readableEmployeeIds === null ? null : array_flip($readableEmployeeIds)),
		];
		$state = ['leaf' => 0, 'depth' => 0, 'nodes' => [], 'edges' => [], 'seen' => []];
		$tree = [];
		foreach ($roots as $unitId) {
			$node = $this->node(unitId: $unitId, depth: 0, context: $context, state: $state);
			if ($node !== null) {
				$tree[] = $node;
			}
		}

		return ['tree' => $tree, 'nodes' => $state['nodes'], 'edges' => $state['edges'], 'width' => $state['leaf'], 'depth' => $state['depth']];
	}//end chart()

	/**
	 * One unit with its subtree, laid out depth first.
	 *
	 * @param string               $unitId  The unit.
	 * @param int                  $depth   Its depth.
	 * @param array<string, mixed> $context Units, children, placements, employees, readable ids.
	 * @param array<string, mixed> $state   Layout state, passed by reference.
	 *
	 * @return array<string, mixed>|null Null when the unit was already placed (a cycle).
	 */
	private function node(string $unitId, int $depth, array $context, array &$state): ?array {
		if (isset($state['seen'][$unitId]) === true) {
			return null;
		}

		$state['seen'][$unitId] = true;
		$state['depth'] = max($state['depth'], ($depth + 1));
		$unit = $context['units'][$unitId];
		$kids = [];
		foreach ($context['children'][$unitId] ?? [] as $childId) {
			$child = $this->node(unitId: $childId, depth: ($depth + 1), context: $context, state: $state);
			if ($child !== null) {
				$kids[] = $child;
				$state['edges'][] = ['source' => $unitId, 'target' => $childId];
			}
		}

		$xPos = (float)$state['leaf'];
		if ($kids === []) {
			$state['leaf']++;
		}

		if ($kids !== []) {
			$xPos = (($kids[0]['x'] + $kids[count($kids) - 1]['x']) / 2);
		}

		$here = $context['placed'][$unitId] ?? [];
		$node = [
			'id'          => $unitId,
			'label'       => (string)($unit['name'] ?? $unitId),
			'managerId'   => ($unit['managerId'] ?? null),
			'managerName' => $this->name(employee: $context['employees'][(string)($unit['managerId'] ?? '')] ?? null),
			'headcount'   => count($here),
			'badge'       => (string)count($here),
			'x'           => $xPos,
			'y'           => $depth,
			'children'    => $kids,
		];
		if ($context['readable'] !== null) {
			$node['people'] = $this->people(placements: $here, context: $context);
		}

		$flat = $node;
		unset($flat['children'], $flat['people']);
		$flat['parentId'] = ($depth === 0 ? null : (string)($unit['parentUnitId'] ?? ''));
		$state['nodes'][] = $flat;

		return $node;
	}//end node()

	/**
	 * The placements live on the date, by unit.
	 *
	 * @param array<int, array<string, mixed>> $assignments OrgAssignment rows.
	 * @param string                           $date        The day.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function placedOn(array $assignments, string $date): array {
		$placed = [];
		foreach ($assignments as $assignment) {
			$unitId = (string)($assignment['orgUnitId'] ?? '');
			if ($unitId !== '' && $this->resolution->isActiveOn($assignment, $date) === true) {
				$placed[$unitId][] = $assignment;
			}
		}

		return $placed;
	}//end placedOn()

	/**
	 * The readable people among a unit's placements.
	 *
	 * @param array<int, array<string, mixed>> $placements The unit's live placements.
	 * @param array<string, mixed>             $context    Employees and readable ids.
	 *
	 * @return array<int, array{id: string, name: string, role: string}>
	 */
	private function people(array $placements, array $context): array {
		$people = [];
		foreach ($placements as $placement) {
			$employeeId = (string)($placement['employeeId'] ?? '');
			if (isset($context['readable'][$employeeId]) === false) {
				continue;
			}

			$people[] = ['id' => $employeeId, 'name' => $this->name(employee: $context['employees'][$employeeId] ?? null), 'role' => (string)($placement['role'] ?? '')];
		}

		return $people;
	}//end people()

	/**
	 * First and last name, or an empty string.
	 *
	 * @param array<string, mixed>|null $employee The employee.
	 *
	 * @return string
	 */
	private function name(?array $employee): string {
		if ($employee === null) {
			return '';
		}

		return trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
	}//end name()
}//end class
