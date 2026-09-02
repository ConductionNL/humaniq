<?php

/**
 * Unit tests for LoonrunFlowRepairService's pure graph helpers
 * (now living in FlowGraphKeypaths).
 *
 * The orchestration half of the service talks to OpenRegister's flow store
 * and is exercised on a live instance; what a standalone suite CAN pin is
 * the part that decides whether a published graph is touched at all — the
 * detection of the v1 keypath defect, the two rewrites (v1 -> corrected and
 * corrected -> v1, the latter deriving the comparison baseline from the
 * shipped file so it cannot drift), and the structural equality that
 * separates "unmodified v1 import" from "somebody's edited graph". Each of
 * these runs against the REAL shipped declaration from hr-objects.json, not
 * a hand-rolled miniature, so the helpers and the file cannot drift apart
 * (payroll-run-as-a-flow design.md D8, REQ-PRF-007).
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\FlowGraphKeypaths;
use OCA\Humaniq\Service\LoonrunFlowRepairService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the v1-keypath detection, the two rewrites and the structural
 * equality against the real shipped declaration.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */
class LoonrunFlowRepairServiceTest extends TestCase {

	/**
	 * The unit under test.
	 *
	 * @return FlowGraphKeypaths
	 */
	private function keypaths(): FlowGraphKeypaths {
		return new FlowGraphKeypaths();
	}//end keypaths()

	/**
	 * The shipped Loonrun nodes from hr-objects.json.
	 *
	 * @return array<int, mixed>
	 */
	private function shippedNodes(): array {
		$path = (__DIR__ . '/../../../lib/Settings/register.d/hr-objects.json');
		$fragment = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($fragment, 'hr-objects.json must parse');

		$flows = ($fragment['components']['schemas'][LoonrunFlowRepairService::FLOW_TRIGGER_SCHEMA]['configuration']['x-openregister-flows'] ?? null);
		$this->assertIsArray($flows, 'The PayrollRun schema must declare x-openregister-flows');

		$nodes = (array)(($flows[0] ?? [])['nodes'] ?? []);
		$this->assertNotSame([], $nodes);

		return $nodes;
	}//end shippedNodes()

	/**
	 * The shipped declaration reads outcomes under `json.` and is therefore
	 * NOT flagged as broken — while its v1 variant is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function testShippedNodesAreCleanAndTheirV1VariantIsBroken(): void {
		$nodes = $this->shippedNodes();
		$keys = $this->keypaths()->outcomeKeys($nodes);
		$this->assertSame(['review'], $keys, 'The Loonrun user task parks its outcome under "review"');

		$this->assertFalse(
			$this->keypaths()->carriesUnprefixedOutcomePath($nodes, $keys),
			'The shipped declaration must read the outcome under json.'
		);

		$v1 = $this->keypaths()->withV1OutcomePaths($nodes, $keys);
		$this->assertTrue(
			$this->keypaths()->carriesUnprefixedOutcomePath($v1, $keys),
			'The derived v1 variant must carry the defect, or the published-graph comparison compares against nothing'
		);

		$this->assertContains('review.outcome', $this->keypaths()->conditionVarPaths($v1));
	}//end testShippedNodesAreCleanAndTheirV1VariantIsBroken()

	/**
	 * The rewrites are exact inverses over the shipped graph, and they touch
	 * ONLY the outcome keypaths — every other byte of the node documents
	 * survives the round trip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function testRewritesRoundTripAndTouchOnlyTheOutcomePaths(): void {
		$nodes = $this->shippedNodes();
		$keys = $this->keypaths()->outcomeKeys($nodes);

		$v1 = $this->keypaths()->withV1OutcomePaths($nodes, $keys);
		$this->assertFalse(
			$this->keypaths()->sameStructure($nodes, $v1),
			'The v1 variant must differ from the shipped graph'
		);

		$roundTripped = $this->keypaths()->withJsonOutcomePaths($v1, $keys);
		$this->assertTrue(
			$this->keypaths()->sameStructure($nodes, $roundTripped),
			'Correcting the v1 variant must restore the shipped graph exactly — the rewrite may touch nothing but the keypaths'
		);
	}//end testRewritesRoundTripAndTouchOnlyTheOutcomePaths()

	/**
	 * A path that is not an outcome keypath is never rewritten: the repair
	 * fixes the defect class, not every `var` in sight.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function testUnrelatedKeypathsAreLeftAlone(): void {
		$nodes = [
			[
				'id' => 'task',
				'type' => 'openregister.user-task',
				'config' => ['outcomeKey' => 'review'],
			],
			[
				'id' => 'gate',
				'type' => 'openregister.switch',
				'exits' => [
					['id' => 'a', 'condition' => ['==' => [['var' => 'review.outcome'], 'approved']]],
					['id' => 'b', 'condition' => ['==' => [['var' => 'json.status'], 'draft']]],
					['id' => 'else'],
				],
			],
		];

		$fixed = $this->keypaths()->withJsonOutcomePaths($nodes, ['review']);
		$paths = $this->keypaths()->conditionVarPaths($fixed);

		$this->assertContains('json.review.outcome', $paths);
		$this->assertContains('json.status', $paths, 'A keypath that never referenced the outcome must be untouched');
		$this->assertNotContains('review.outcome', $paths);
		$this->assertNotContains('json.json.status', $paths, 'The rewrite must not double-prefix');
	}//end testUnrelatedKeypathsAreLeftAlone()

	/**
	 * Structural equality separates an unmodified import from an edited
	 * graph: key order is noise, a changed value is a difference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function testSameStructureIgnoresKeyOrderAndCatchesEdits(): void {
		$nodes = $this->shippedNodes();

		$reordered = array_map(
			static function (mixed $node): mixed {
				if (is_array($node) === false) {
					return $node;
				}

				krsort($node);

				return $node;
			},
			$nodes
		);
		$this->assertTrue(
			$this->keypaths()->sameStructure($nodes, $reordered),
			'Associative key order is storage noise, not a user edit'
		);

		$edited = $nodes;
		$edited[0]['id'] = ((string)$edited[0]['id'] . '-edited');
		$this->assertFalse(
			$this->keypaths()->sameStructure($nodes, $edited),
			'A changed value must read as a modified graph'
		);
	}//end testSameStructureIgnoresKeyOrderAndCatchesEdits()

}//end class
