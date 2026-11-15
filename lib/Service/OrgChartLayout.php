<?php

/**
 * Humaniq OrgChartLayout
 *
 * The depth-first layout of one organisation chart: each unit with its
 * subtree, x the position among the leaves and y the depth, and the flat
 * nodes and edges of the drawn chart.
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
 * Lays out one chart; built by OrgChartService, used once.
 *
 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
 */
final class OrgChartLayout {

	/**
	 * The active units by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $units = [];

	/**
	 * The children per unit.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $children = [];

	/**
	 * The live placements by unit.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $placed = [];

	/**
	 * Employee rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $employees = [];

	/**
	 * The readable employee ids as keys; null lists no people.
	 *
	 * @var array<string, int>|null
	 */
	public ?array $readable = null;

	/**
	 * The leaves placed so far.
	 *
	 * @var int
	 */
	public int $leaf = 0;

	/**
	 * The deepest level reached, in levels.
	 *
	 * @var int
	 */
	public int $depth = 0;

	/**
	 * The flat nodes, in layout order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $nodes = [];

	/**
	 * The parent-to-child edges.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $edges = [];

	/**
	 * The units already placed, as keys.
	 *
	 * @var array<string, bool>
	 */
	private array $seen = [];

	/**
	 * One unit with its subtree, laid out depth first.
	 *
	 * @param string $unitId The unit.
	 * @param int    $depth  Its depth.
	 *
	 * @return array<string, mixed>|null Null when the unit was already placed (a cycle).
	 *
	 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
	 */
	public function node(string $unitId, int $depth): ?array {
		if (isset($this->seen[$unitId]) === true) {
			return null;
		}

		$this->seen[$unitId] = true;
		$this->depth = max($this->depth, ($depth + 1));
		$kids = $this->childNodes(unitId: $unitId, depth: $depth);
		$node = $this->unitNode(unitId: $unitId) + ['x' => $this->xOf(kids: $kids), 'y' => $depth, 'children' => $kids];
		if ($this->readable !== null) {
			$node['people'] = $this->people(placements: ($this->placed[$unitId] ?? []));
		}

		$flat = $node;
		unset($flat['children'], $flat['people']);
		$flat['parentId'] = ($depth === 0 ? null : (string)($this->units[$unitId]['parentUnitId'] ?? ''));
		$this->nodes[] = $flat;

		return $node;
	}//end node()

	/**
	 * The laid-out children of a unit, with an edge to each.
	 *
	 * @param string $unitId The unit.
	 * @param int    $depth  Its depth.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function childNodes(string $unitId, int $depth): array {
		$kids = [];
		foreach ($this->children[$unitId] ?? [] as $childId) {
			$child = $this->node(unitId: $childId, depth: ($depth + 1));
			if ($child !== null) {
				$kids[] = $child;
				$this->edges[] = ['source' => $unitId, 'target' => $childId];
			}
		}

		return $kids;
	}//end childNodes()

	/**
	 * A leaf takes the next position; a parent sits above the middle of its
	 * first and last child.
	 *
	 * @param array<int, array<string, mixed>> $kids The laid-out children.
	 *
	 * @return float
	 */
	private function xOf(array $kids): float {
		if ($kids === []) {
			return (float)$this->leaf++;
		}

		return (($kids[0]['x'] + $kids[count($kids) - 1]['x']) / 2);
	}//end xOf()

	/**
	 * The unit's own fields: name, manager and headcount on the date.
	 *
	 * @param string $unitId The unit.
	 *
	 * @return array<string, mixed>
	 */
	private function unitNode(string $unitId): array {
		$unit = $this->units[$unitId];
		$headcount = count($this->placed[$unitId] ?? []);

		return [
			'id'          => $unitId,
			'label'       => (string)($unit['name'] ?? $unitId),
			'managerId'   => ($unit['managerId'] ?? null),
			'managerName' => self::name(employee: ($this->employees[(string)($unit['managerId'] ?? '')] ?? null)),
			'headcount'   => $headcount,
			'badge'       => (string)$headcount,
		];
	}//end unitNode()

	/**
	 * The readable people among a unit's placements.
	 *
	 * @param array<int, array<string, mixed>> $placements The unit's live placements.
	 *
	 * @return array<int, array{id: string, name: string, role: string}>
	 */
	private function people(array $placements): array {
		$people = [];
		foreach ($placements as $placement) {
			$employeeId = (string)($placement['employeeId'] ?? '');
			if (isset($this->readable[$employeeId]) === false) {
				continue;
			}

			$people[] = ['id' => $employeeId, 'name' => self::name(employee: ($this->employees[$employeeId] ?? null)), 'role' => (string)($placement['role'] ?? '')];
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
	private static function name(?array $employee): string {
		if ($employee === null) {
			return '';
		}

		return trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
	}//end name()
}//end class
