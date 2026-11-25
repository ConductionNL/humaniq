<?php

/**
 * Flow Step Splicer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Splices a pass-through step out of a flow graph, so a graph can be
 * compared with the form it had before that step was inserted.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-run-pay-date-and-schedule/tasks.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Removes one pass-through step from a flow graph and rewires its edges.
 *
 * @spec openspec/changes/payroll-run-pay-date-and-schedule/tasks.md
 */
class FlowStepSplicer {

	/**
	 * A graph with one pass-through step spliced out: the node is dropped,
	 * the edge into it is pointed at where the node's own outgoing edge went,
	 * and that outgoing edge is dropped. Reconstructs a graph as it was
	 * before the step was inserted (the Loonrun's v1 had no pay-day wait
	 * between approve and glpost).
	 *
	 * A node with no single outgoing edge, or not in the graph, leaves the
	 * graph unchanged.
	 *
	 * @param array<int, mixed> $nodes  The graph's nodes.
	 * @param array<int, mixed> $edges  The graph's edges.
	 * @param string            $nodeId The step to splice out.
	 *
	 * @return array{nodes: array<int, mixed>, edges: array<int, mixed>} The graph without the step.
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/tasks.md
	 */
	public function withoutStep(array $nodes, array $edges, string $nodeId): array {
		$outgoing = array_values(
			array_filter($edges, static fn ($edge): bool => is_array($edge) === true && ($edge['from'] ?? null) === $nodeId)
		);
		$kept = array_values(
			array_filter($nodes, static fn ($node): bool => is_array($node) === false || ($node['id'] ?? null) !== $nodeId)
		);
		if (count($outgoing) !== 1 || count($kept) === count($nodes)) {
			return ['nodes' => $nodes, 'edges' => $edges];
		}

		$next = $outgoing[0]['to'] ?? null;
		$spliced = [];
		foreach ($edges as $edge) {
			if (is_array($edge) === true && ($edge['from'] ?? null) === $nodeId) {
				continue;
			}

			if (is_array($edge) === true && ($edge['to'] ?? null) === $nodeId) {
				$edge['to'] = $next;
			}

			$spliced[] = $edge;
		}

		return ['nodes' => $kept, 'edges' => $spliced];

	}//end withoutStep()
}//end class
