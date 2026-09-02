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

}//end class
