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
	 * @param OrgChartRequest $request The rows, the day, the root and the readable people.
	 *
	 * @return array{tree: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, edges: array<int, array<string, string>>, width: int, depth: int}
	 *
	 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
	 */
	public function chart(OrgChartRequest $request): array {
		$layout = $this->layoutFor(request: $request);
		$tree = [];
		foreach ($this->rootsOf(request: $request, layout: $layout) as $unitId) {
			$node = $layout->node(unitId: $unitId, depth: 0);
			if ($node !== null) {
				$tree[] = $node;
			}
		}

		return ['tree' => $tree, 'nodes' => $layout->nodes, 'edges' => $layout->edges, 'width' => $layout->leaf, 'depth' => $layout->depth];
	}//end chart()

	/**
	 * The layout over the active units, their children, the placements live
	 * on the day, the employees and the readable ids.
	 *
	 * @param OrgChartRequest $request The rows and the view.
	 *
	 * @return OrgChartLayout
	 */
	private function layoutFor(OrgChartRequest $request): OrgChartLayout {
		$layout = new OrgChartLayout();
		$layout->units = $this->activeUnits(units: $request->units);
		$layout->children = $this->hierarchy(active: $layout->units)[1];
		$layout->placed = $this->placedOn(assignments: $request->assignments, date: $request->date);
		$layout->employees = $request->employeesById;
		$layout->readable = ($request->readableEmployeeIds === null ? null : array_flip($request->readableEmployeeIds));

		return $layout;
	}//end layoutFor()

	/**
	 * The units the chart starts from: the asked root when it is active, else
	 * every unit without an active parent.
	 *
	 * @param OrgChartRequest $request The view.
	 * @param OrgChartLayout  $layout  The active units.
	 *
	 * @return array<int, string>
	 */
	private function rootsOf(OrgChartRequest $request, OrgChartLayout $layout): array {
		if ($request->rootId !== null && $request->rootId !== '') {
			return array_values(array_intersect([$request->rootId], array_keys($layout->units)));
		}

		return $this->hierarchy(active: $layout->units)[0];
	}//end rootsOf()

	/**
	 * The active units by id.
	 *
	 * @param array<int, array<string, mixed>> $units OrgUnit rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function activeUnits(array $units): array {
		$active = [];
		foreach ($units as $unit) {
			$unitId = (string)($unit['id'] ?? '');
			if ($unitId !== '' && ($unit['active'] ?? true) !== false) {
				$active[$unitId] = $unit;
			}
		}

		return $active;
	}//end activeUnits()

	/**
	 * The roots and the children per unit. A unit whose parent is missing or
	 * inactive is a root.
	 *
	 * @param array<string, array<string, mixed>> $active The active units by id.
	 *
	 * @return array{0: array<int, string>, 1: array<string, array<int, string>>}
	 */
	private function hierarchy(array $active): array {
		$children = [];
		$roots = [];
		foreach ($active as $unitId => $unit) {
			$parentId = (string)($unit['parentUnitId'] ?? '');
			if ($parentId !== '' && isset($active[$parentId]) === true && $parentId !== (string)$unitId) {
				$children[$parentId][] = (string)$unitId;
				continue;
			}

			$roots[] = (string)$unitId;
		}

		return [$roots, $children];
	}//end hierarchy()

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
}//end class
