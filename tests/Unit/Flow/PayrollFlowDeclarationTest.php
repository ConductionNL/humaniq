<?php

/**
 * Structural pins for the shipped Loonrun flow declaration — the
 * present-is-not-wired guard between hr-objects.json and lib/Flow/
 * (REQ-PRF-005), plus the no-self-scoping source scan (REQ-PRF-006).
 *
 * The dossiq CaseFlowDeclarationTest precedent: a declared flow is imported
 * by OpenRegister without compiling against this app's code, so nothing but
 * a test ties the JSON's node types to the classes the listener registers.
 * A typo'd type would import cleanly and produce a step that never resolves.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-005
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\HumaniqFlowNodeListener;
use OCA\Humaniq\Flow\PayrollApproveNode;
use OCA\Humaniq\Flow\PayrollCalculateNode;
use OCA\Humaniq\Flow\PayrollGlPostNode;
use OCA\Humaniq\Flow\PayrollNetPayNode;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\PayrollRunService;
use RuntimeException;

/**
 * Tests pinning the shipped flow JSON to the contributed node ids and the
 * engine's graph rules.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-005
 */
class PayrollFlowDeclarationTest extends FlowNodeTestCase {

	/**
	 * The engine-owned node types the declaration may use — pinned so a typo
	 * ships as a red test, not as an unresolvable imported step.
	 *
	 * @var array<int, string>
	 */
	private const ENGINE_TYPES = [
		'openregister.trigger-manual',
		'openregister.user-task',
		'openregister.switch',
		'openregister.end',
	];

	/**
	 * The shipped Loonrun declaration from hr-objects.json.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$path = (__DIR__ . '/../../../lib/Settings/register.d/hr-objects.json');
		$fragment = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($fragment, 'hr-objects.json must parse');

		$flows = ($fragment['components']['schemas']['PayrollRun']['configuration']['x-openregister-flows'] ?? null);
		$this->assertIsArray($flows, 'The PayrollRun schema must declare x-openregister-flows');
		$this->assertCount(1, $flows);

		return $flows[0];
	}//end declaration()

	/**
	 * The `humaniq.*` ids the listener actually registers, computed by
	 * instantiating every listed node class — so the JSON can only name
	 * types that resolve at runtime.
	 *
	 * @return array<int, string>
	 */
	private function registeredIds(): array {
		$common = [
			$this->l10n(),
			$this->urls(),
			$this->container($this->fakeObjectService()),
			$this->settings(),
			$this->logger(),
		];

		$ids = [];
		foreach (HumaniqFlowNodeListener::nodeClasses() as $nodeClass) {
			$node = match ($nodeClass) {
				PayrollCalculateNode::class => new PayrollCalculateNode(...array_merge($common, [$this->createMock(PayrollRunService::class)])),
				PayrollApproveNode::class => new PayrollApproveNode(...$common),
				PayrollGlPostNode::class => new PayrollGlPostNode(...array_merge($common, [$this->createMock(PayrollGLPostService::class)])),
				PayrollNetPayNode::class => new PayrollNetPayNode(...array_merge($common, [$this->createMock(PayrollNetPayService::class)])),
				default => throw new RuntimeException('Unlisted node class ' . $nodeClass . ' — extend this map.'),
			};
			$ids[] = $node->getId();
		}

		return $ids;
	}//end registeredIds()

	/**
	 * Every declared node type resolves: `humaniq.*` to a listener-registered
	 * id, `openregister.*` to the pinned engine vocabulary.
	 *
	 * @return void
	 */
	public function testEveryNodeTypeResolves(): void {
		$registered = $this->registeredIds();

		foreach (($this->declaration()['nodes'] ?? []) as $node) {
			$type = (string)($node['type'] ?? '');
			if (str_starts_with($type, 'humaniq.') === true) {
				$this->assertContains($type, $registered, 'Node type ' . $type . ' is not registered by HumaniqFlowNodeListener');
				continue;
			}

			$this->assertContains($type, self::ENGINE_TYPES, 'Unexpected engine node type ' . $type);
		}
	}//end testEveryNodeTypeResolves()

	/**
	 * All four contributed steps appear in the flow — a node the palette
	 * offers but no shipped flow uses would be a capability without a caller.
	 *
	 * @return void
	 */
	public function testAllContributedNodesAreUsed(): void {
		$types = array_map(
			static fn (array $node): string => (string)($node['type'] ?? ''),
			($this->declaration()['nodes'] ?? [])
		);

		foreach ($this->registeredIds() as $id) {
			$this->assertContains($id, $types, 'Registered node ' . $id . ' is unused by the shipped flow');
		}
	}//end testAllContributedNodesAreUsed()

	/**
	 * Every edge endpoint resolves to a declared node id, and every
	 * `fromExit` names a declared exit on its source node.
	 *
	 * @return void
	 */
	public function testEdgesResolve(): void {
		$declaration = $this->declaration();
		$nodesById = [];
		foreach (($declaration['nodes'] ?? []) as $node) {
			$nodesById[(string)($node['id'] ?? '')] = $node;
		}

		foreach (($declaration['edges'] ?? []) as $edge) {
			$from = (string)($edge['from'] ?? '');
			$to = (string)($edge['to'] ?? '');
			$this->assertArrayHasKey($from, $nodesById, 'Dangling edge source ' . $from);
			$this->assertArrayHasKey($to, $nodesById, 'Dangling edge target ' . $to);

			$fromExit = (string)($edge['fromExit'] ?? '');
			if ($fromExit === '') {
				continue;
			}

			$exitIds = array_map(
				static fn (array $exit): string => (string)($exit['id'] ?? ''),
				(array)($nodesById[$from]['exits'] ?? [])
			);
			$this->assertContains($fromExit, $exitIds, 'Edge ' . (string)($edge['id'] ?? '') . ' names undeclared exit ' . $fromExit);
		}
	}//end testEdgesResolve()

	/**
	 * A node that conditions any exit declares an unconditioned else, and
	 * every declared exit is bound by an edge — the engine's own build-time
	 * refusal, asserted here so it never fires at import time.
	 *
	 * @return void
	 */
	public function testConditionedExitsCarryAnElseAndAreWired(): void {
		$declaration = $this->declaration();
		$edges = ($declaration['edges'] ?? []);

		foreach (($declaration['nodes'] ?? []) as $node) {
			$exits = (array)($node['exits'] ?? []);
			if ($exits === []) {
				continue;
			}

			$hasElse = false;
			foreach ($exits as $exit) {
				$condition = ($exit['condition'] ?? null);
				if (is_array($condition) === false || $condition === []) {
					$hasElse = true;
				}

				$exitId = (string)($exit['id'] ?? '');
				$bound = array_filter(
					$edges,
					static fn (array $edge): bool => (string)($edge['from'] ?? '') === (string)($node['id'] ?? '')
						&& (string)($edge['fromExit'] ?? '') === $exitId
				);
				$this->assertNotSame([], $bound, 'Exit ' . $exitId . ' of node ' . (string)($node['id'] ?? '') . ' is bound by no edge');
			}

			$this->assertTrue($hasElse, 'Node ' . (string)($node['id'] ?? '') . ' conditions exits without an unconditioned else');
		}
	}//end testConditionedExitsCarryAnElseAndAreWired()

	/**
	 * The declaration ships inert: no owner, no enabled flag, no schedule
	 * trigger — adoption and the runAs decision stay the admin's deliberate
	 * act (design.md D5).
	 *
	 * @return void
	 */
	public function testDeclarationShipsInert(): void {
		$declaration = $this->declaration();

		$this->assertArrayNotHasKey('owner', $declaration);
		$this->assertArrayNotHasKey('enabled', $declaration);
		$this->assertArrayNotHasKey('cron', $declaration);
		$this->assertSame('manual', $declaration['trigger'] ?? null);

		foreach (($declaration['nodes'] ?? []) as $node) {
			$this->assertNotSame('openregister.trigger-schedule', (string)($node['type'] ?? ''));
		}
	}//end testDeclarationShipsInert()

	/**
	 * The nodes rely on the dispatcher's runAs scoping: none references
	 * `runAs`, `runAsSystem` or `IFlowSelfScopedNode` (REQ-PRF-006).
	 *
	 * @return void
	 */
	public function testNoSelfScopingShips(): void {
		$flowDir = (__DIR__ . '/../../../lib/Flow');
		$files = glob($flowDir . '/*.php');
		$this->assertNotSame([], $files);

		foreach ((array)$files as $file) {
			$source = (string)file_get_contents((string)$file);
			foreach (['runAs(', 'runAsSystem', 'IFlowSelfScopedNode'] as $marker) {
				$this->assertStringNotContainsString(
					$marker,
					$source,
					basename((string)$file) . ' must not self-scope (' . $marker . ') — the dispatcher owns the acting identity'
				);
			}
		}
	}//end testNoSelfScopingShips()

	/**
	 * The gate condition, EVALUATED against an engine-shaped item: only an
	 * approved review takes the approved exit (REQ-PRF-007).
	 *
	 * v1 shipped `{"var": "review.outcome"}` and every approval took the
	 * rejected exit, because the engine places the outcome bag INSIDE the
	 * item's record — under `json.<outcomeKey>` — and hands conditions the
	 * record under the `json` key of the data document. A string assertion
	 * on the condition would rot; this routes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function testGateConditionRoutesOnlyAnApprovedReviewDownTheApprovedExit(): void {
		$outcomeKey = $this->declaredOutcomeKey();
		$condition = $this->approvedExitCondition();

		$this->assertTrue(
			(bool)$this->evaluateCondition($condition, $this->conditionData($this->engineShapedItem($outcomeKey, 'approved'))),
			'An approved review must satisfy the approved-exit condition — the v1 keypath sent every approval to end-rejected'
		);

		$this->assertFalse(
			(bool)$this->evaluateCondition($condition, $this->conditionData($this->engineShapedItem($outcomeKey, 'rejected'))),
			'A rejected review must not satisfy the approved-exit condition'
		);

		$this->assertFalse(
			(bool)$this->evaluateCondition($condition, $this->conditionData(['json' => []])),
			'An item that never met the user task must not route as approved'
		);
	}//end testGateConditionRoutesOnlyAnApprovedReviewDownTheApprovedExit()

	/**
	 * Every condition keypath that references a user task's outcome reads it
	 * under `json.`, and at least one does at all — so the guard cannot pass
	 * vacuously (REQ-PRF-007).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function testOutcomeConditionsReadTheRecordKeypath(): void {
		$outcomeKeys = [];
		foreach (($this->declaration()['nodes'] ?? []) as $node) {
			if ((string)($node['type'] ?? '') !== 'openregister.user-task') {
				continue;
			}

			$key = trim((string)(($node['config'] ?? [])['outcomeKey'] ?? ''));
			$this->assertNotSame('', $key, 'A user-task node must declare an outcomeKey');
			$outcomeKeys[] = $key;
		}

		$this->assertNotSame([], $outcomeKeys, 'The flow must carry a user task, or this guard asserts nothing');

		$paths = $this->conditionVarPaths();
		$this->assertNotSame([], $paths, 'The flow must carry a conditioned exit, or this guard asserts nothing');

		$readsAnOutcome = false;
		foreach ($paths as $path) {
			foreach ($outcomeKeys as $key) {
				$this->assertFalse(
					$path === $key || str_starts_with($path, $key . '.') === true,
					'Condition keypath "' . $path . '" reads the outcome bag beside the record. UserTaskNode::placeOutcome() writes it INTO the record, so the path must start with "json.' . $key . '"'
				);

				if ($path === ('json.' . $key) || str_starts_with($path, 'json.' . $key . '.') === true) {
					$readsAnOutcome = true;
				}
			}
		}

		$this->assertTrue($readsAnOutcome, 'No condition reads a user-task outcome at all — the keypath guard would be vacuous');
	}//end testOutcomeConditionsReadTheRecordKeypath()

	/**
	 * The declared `review` node's outcomeKey.
	 *
	 * @return string The key the engine parks the outcome bag under.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function declaredOutcomeKey(): string {
		foreach (($this->declaration()['nodes'] ?? []) as $node) {
			if ((string)($node['type'] ?? '') === 'openregister.user-task') {
				$key = trim((string)(($node['config'] ?? [])['outcomeKey'] ?? ''));
				$this->assertNotSame('', $key, 'The review node must declare an outcomeKey');

				return $key;
			}
		}

		$this->fail('The declaration carries no openregister.user-task node');
	}//end declaredOutcomeKey()

	/**
	 * The condition on the gate's `approved` exit.
	 *
	 * @return array<string, mixed> The JSONLogic expression.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function approvedExitCondition(): array {
		foreach (($this->declaration()['nodes'] ?? []) as $node) {
			foreach ((array)($node['exits'] ?? []) as $exit) {
				if ((string)($exit['id'] ?? '') === 'approved') {
					$condition = ($exit['condition'] ?? null);
					$this->assertIsArray($condition, 'The approved exit must carry a condition');

					return $condition;
				}
			}
		}

		$this->fail('The declaration carries no approved exit');
	}//end approvedExitCondition()

	/**
	 * Every `var` keypath referenced by any exit condition.
	 *
	 * @return array<int, string> The keypaths.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function conditionVarPaths(): array {
		$paths = [];
		foreach (($this->declaration()['nodes'] ?? []) as $node) {
			foreach ((array)($node['exits'] ?? []) as $exit) {
				$this->collectVarPaths(($exit['condition'] ?? null), $paths);
			}
		}

		return $paths;
	}//end conditionVarPaths()

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
	 * An item as the engine hands it to the gate after the review resolves.
	 *
	 * Modelled from OpenRegister on development, not from memory or from the
	 * caller: `UserTaskNode::placeOutcome()` does `json[$outcomeKey] = $bag`
	 * on the item, and the bag's keys are `FlowTaskBridge::outcomeBagFor()`'s
	 * (taskUuid, state, decided, outcome, rejected, comment, result,
	 * completedBy, completedAt, performerType, assignee, onBehalfOf,
	 * mandate). A bag invented from the condition's own expectations would
	 * be the fake-agrees-with-caller trap.
	 *
	 * @param string $outcomeKey Where the node's config parks the bag.
	 * @param string $outcome The recorded decision.
	 *
	 * @return array<string, mixed> The engine-shaped item.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function engineShapedItem(string $outcomeKey, string $outcome): array {
		return [
			'json' => [
				$outcomeKey => [
					'taskUuid' => '00000000-0000-4000-8000-000000000001',
					'state' => 'completed',
					'decided' => true,
					'outcome' => $outcome,
					'rejected' => ($outcome === 'rejected'),
					'comment' => null,
					'result' => null,
					'completedBy' => 'reviewer',
					'completedAt' => '2026-09-02T12:00:00+00:00',
					'performerType' => 'group',
					'assignee' => null,
					'onBehalfOf' => null,
					'mandate' => null,
				],
			],
		];
	}//end engineShapedItem()

	/**
	 * The data document a condition is evaluated against — the shape of
	 * OpenRegister's `FlowExpression::dataFor()`: the item's record under
	 * `json`, binaries under `binary`, plus run metadata. Which is exactly
	 * why a bare `review.outcome` resolves to nothing.
	 *
	 * @param array<string, mixed> $item The engine-shaped item.
	 *
	 * @return array<string, mixed> The data document.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function conditionData(array $item): array {
		return [
			'json' => (array)($item['json'] ?? []),
			'binary' => [],
			'itemIndex' => 0,
			'itemCount' => 1,
			'context' => [],
			'subject' => [],
		];
	}//end conditionData()

	/**
	 * A minimal JSONLogic evaluator covering exactly the operators the
	 * shipped conditions use, with the engine's semantics: a `var` walks the
	 * dot path and yields null when any segment is missing, `==` is loose
	 * (jwadhams JsonLogic), and null never satisfies a condition. Any other
	 * operator fails the test rather than guessing at its semantics.
	 *
	 * @param mixed $logic The JSONLogic expression.
	 * @param array<string, mixed> $data The data document.
	 *
	 * @return mixed The evaluation result.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function evaluateCondition(mixed $logic, array $data): mixed {
		if (is_array($logic) === false) {
			return $logic;
		}

		$operator = array_key_first($logic);
		$arguments = $logic[$operator];

		if ($operator === 'var') {
			$path = (is_array($arguments) === true) ? (string)($arguments[0] ?? '') : (string)$arguments;

			return $this->lookupPath($path, $data);
		}

		if ($operator === '==') {
			$left = $this->evaluateCondition(($arguments[0] ?? null), $data);
			$right = $this->evaluateCondition(($arguments[1] ?? null), $data);

			// JSONLogic's == is loose equality, deliberately.
			return ($left == $right);
		}

		$this->fail('The shipped condition uses operator "' . (string)$operator . '", which this evaluator does not model. Extend it from openregister FlowExpression.');
	}//end evaluateCondition()

	/**
	 * Walk a dot-separated keypath into the data document; null when any
	 * segment is missing, matching JsonLogic's `var`.
	 *
	 * @param string $path The keypath.
	 * @param array<string, mixed> $data The data document.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function lookupPath(string $path, array $data): mixed {
		$value = $data;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}//end lookupPath()

}//end class
