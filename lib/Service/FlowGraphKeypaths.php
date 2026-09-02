<?php

/**
 * Flow Graph Keypaths
 *
 * Pure helpers over a flow graph's exit conditions, split out of
 * `LoonrunFlowRepairService` so the store-touching orchestration and the
 * graph arithmetic each stay readable — and so the arithmetic is testable
 * without OpenRegister on the autoloader.
 *
 * The subject matter: OpenRegister's `UserTaskNode::placeOutcome()` writes a
 * user task's outcome bag INTO the item's record (`json.<outcomeKey>`), and
 * `FlowExpression::dataFor()` hands a condition the record under the `json`
 * key of its data document. A condition reading the bare outcome key
 * therefore resolves to null, null is false, and the branch is never taken —
 * the silently wrong keypath the v1 Loonrun declaration shipped
 * (payroll-run-as-a-flow design.md D8). These helpers detect that class,
 * rewrite it in either direction, and compare graphs structurally.
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Detects, rewrites and compares user-task outcome keypaths in a flow
 * graph's exit conditions.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */
class FlowGraphKeypaths {

	/**
	 * The corrected keypath prefix: a user task's outcome bag lives INSIDE
	 * the item's record, which a condition reads under `json`.
	 *
	 * @var string
	 */
	public const JSON_PREFIX = 'json.';

	/**
	 * Every `outcomeKey` declared by a user-task node in a node list.
	 *
	 * @param array<int, mixed> $nodes The graph's nodes.
	 *
	 * @return array<int, string> The outcome keys, deduplicated.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function outcomeKeys(array $nodes): array {
		$keys = [];
		foreach ($nodes as $node) {
			if (is_array($node) === false || (string)($node['type'] ?? '') !== 'openregister.user-task') {
				continue;
			}

			$key = trim((string)(($node['config'] ?? [])['outcomeKey'] ?? ''));
			if ($key !== '') {
				$keys[] = $key;
			}
		}

		return array_values(array_unique($keys));

	}//end outcomeKeys()

	/**
	 * Whether any exit condition reads an outcome bag beside the record —
	 * a `var` path on the bare outcome key instead of under `json.`.
	 *
	 * @param array<int, mixed> $nodes The graph's nodes.
	 * @param array<int, string> $outcomeKeys The user-task outcome keys.
	 *
	 * @return bool True when the v1 defect is present.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function carriesUnprefixedOutcomePath(array $nodes, array $outcomeKeys): bool {
		foreach ($this->conditionVarPaths($nodes) as $path) {
			foreach ($outcomeKeys as $key) {
				if ($path === $key || str_starts_with($path, $key . '.') === true) {
					return true;
				}
			}
		}

		return false;

	}//end carriesUnprefixedOutcomePath()

	/**
	 * The nodes with every bare outcome keypath rewritten under `json.` —
	 * the corrected form.
	 *
	 * @param array<int, mixed> $nodes The graph's nodes.
	 * @param array<int, string> $outcomeKeys The user-task outcome keys.
	 *
	 * @return array<int, mixed> The rewritten nodes.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function withJsonOutcomePaths(array $nodes, array $outcomeKeys): array {
		return $this->rewriteVarPaths(
			$nodes,
			function (string $path) use ($outcomeKeys): string {
				foreach ($outcomeKeys as $key) {
					if ($path === $key || str_starts_with($path, $key . '.') === true) {
						return self::JSON_PREFIX . $path;
					}
				}

				return $path;
			}
		);

	}//end withJsonOutcomePaths()

	/**
	 * The nodes with every `json.`-prefixed outcome keypath rewritten back
	 * to the bare v1 form — the shape the v1 import published.
	 *
	 * @param array<int, mixed> $nodes The graph's nodes.
	 * @param array<int, string> $outcomeKeys The user-task outcome keys.
	 *
	 * @return array<int, mixed> The rewritten nodes.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function withV1OutcomePaths(array $nodes, array $outcomeKeys): array {
		return $this->rewriteVarPaths(
			$nodes,
			function (string $path) use ($outcomeKeys): string {
				foreach ($outcomeKeys as $key) {
					$prefixed = self::JSON_PREFIX . $key;
					if ($path === $prefixed || str_starts_with($path, $prefixed . '.') === true) {
						return substr($path, strlen(self::JSON_PREFIX));
					}
				}

				return $path;
			}
		);

	}//end withV1OutcomePaths()

	/**
	 * Every `var` keypath referenced by any exit condition in a node list.
	 *
	 * @param array<int, mixed> $nodes The graph's nodes.
	 *
	 * @return array<int, string> The keypaths, in document order.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function conditionVarPaths(array $nodes): array {
		$paths = [];
		foreach ($nodes as $node) {
			if (is_array($node) === false) {
				continue;
			}

			foreach ((array)($node['exits'] ?? []) as $exit) {
				if (is_array($exit) === true) {
					$this->collectVarPaths(($exit['condition'] ?? null), $paths);
				}
			}
		}

		return $paths;

	}//end conditionVarPaths()

	/**
	 * Structural equality for JSON-shaped documents: associative keys are
	 * order-insensitive, list order matters, values compare strictly after
	 * normalisation.
	 *
	 * @param array<int|string, mixed> $left One document.
	 * @param array<int|string, mixed> $right The other.
	 *
	 * @return bool Whether they carry the same structure and values.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function sameStructure(array $left, array $right): bool {
		return json_encode($this->normalised($left)) === json_encode($this->normalised($right));

	}//end sameStructure()

	/**
	 * Collect `var` keypaths from one JSONLogic expression, recursively.
	 *
	 * @param mixed $logic The expression.
	 * @param array<int, string> $paths Collector, appended to in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function collectVarPaths(mixed $logic, array &$paths): void {
		if (is_array($logic) === false) {
			return;
		}

		foreach ($logic as $operator => $arguments) {
			if ($operator === 'var') {
				$paths[] = (is_array($arguments) === true) ? (string)($arguments[0] ?? '') : (string)$arguments;
				continue;
			}

			foreach ((array)$arguments as $argument) {
				$this->collectVarPaths($argument, $paths);
			}
		}

	}//end collectVarPaths()

	/**
	 * Rewrite every `var` keypath in every exit condition through a mapper,
	 * touching nothing else in the node documents.
	 *
	 * @param array<int, mixed> $nodes The graph's nodes.
	 * @param callable(string): string $map Old path in, new path out.
	 *
	 * @return array<int, mixed> The rewritten nodes.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function rewriteVarPaths(array $nodes, callable $map): array {
		foreach ($nodes as $nodeIndex => $node) {
			if (is_array($node) === false || is_array($node['exits'] ?? null) === false) {
				continue;
			}

			foreach ($node['exits'] as $exitIndex => $exit) {
				if (is_array($exit) === false || isset($exit['condition']) === false) {
					continue;
				}

				$nodes[$nodeIndex]['exits'][$exitIndex]['condition'] = $this->rewriteLogicVars($exit['condition'], $map);
			}
		}

		return $nodes;

	}//end rewriteVarPaths()

	/**
	 * Rewrite the `var` keypaths inside one JSONLogic expression.
	 *
	 * @param mixed $logic The expression.
	 * @param callable(string): string $map Old path in, new path out.
	 *
	 * @return mixed The rewritten expression.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function rewriteLogicVars(mixed $logic, callable $map): mixed {
		if (is_array($logic) === false) {
			return $logic;
		}

		foreach ($logic as $operator => $arguments) {
			if ($operator === 'var') {
				$path = (is_array($arguments) === true) ? (string)($arguments[0] ?? '') : (string)$arguments;
				$logic[$operator] = (is_array($arguments) === true)
					? array_replace($arguments, [0 => $map($path)])
					: $map($path);
				continue;
			}

			if (is_array($arguments) === true) {
				foreach ($arguments as $argumentIndex => $argument) {
					$logic[$operator][$argumentIndex] = $this->rewriteLogicVars($argument, $map);
				}
			}
		}

		return $logic;

	}//end rewriteLogicVars()

	/**
	 * Sort associative keys recursively so encoding is order-independent.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return mixed The normalised value.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function normalised(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$normalised = array_map(fn (mixed $entry): mixed => $this->normalised($entry), $value);
		if (array_is_list($normalised) === false) {
			ksort($normalised);
		}

		return $normalised;

	}//end normalised()

}//end class
