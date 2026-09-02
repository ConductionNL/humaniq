<?php

/**
 * Unit tests for PayrollApproveNode — the draft-only flip, the status-only
 * write and the refusals (REQ-PRF-003).
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\PayrollApproveNode;
use RuntimeException;

/**
 * Tests for the approve node.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
 */
class PayrollApproveNodeTest extends FlowNodeTestCase {

	/**
	 * Build the node around a fake ObjectService.
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return PayrollApproveNode
	 */
	private function node(object $objectService): PayrollApproveNode {
		return new PayrollApproveNode(
			$this->l10n(),
			$this->urls(),
			$this->container($objectService),
			$this->settings(),
			$this->logger()
		);
	}//end node()

	/**
	 * A draft PayrollRun row.
	 *
	 * @return array<string, mixed>
	 */
	private function draftRun(): array {
		return [
			'id' => 'run-1',
			'period' => '2026-05',
			'administrationId' => 'ADM-001',
			'status' => 'draft',
			'totalNet' => 2500.00,
			'@self' => ['id' => 'run-1'],
		];
	}//end draftRun()

	/**
	 * A draft run named by `payroll.runId` is flipped to approved with a
	 * status-only change, `@self` stripped, saved under its own uuid.
	 *
	 * @return void
	 */
	public function testApprovesDraftRunStatusOnly(): void {
		$objectService = $this->fakeObjectService(['PayrollRun' => [$this->draftRun()]]);

		$out = $this->node($objectService)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);

		$this->assertCount(1, $objectService->saved);
		$write = $objectService->saved[0];
		$this->assertSame('PayrollRun', $write['schema']);
		$this->assertSame('run-1', $write['uuid']);
		$this->assertSame('approved', $write['object']['status']);
		$this->assertArrayNotHasKey('@self', $write['object']);

		// Status-only: every other field of the run travels unchanged.
		$this->assertSame(2500.00, $write['object']['totalNet']);
		$this->assertSame('2026-05', $write['object']['period']);
		$this->assertSame(
			['runId', 'status', 'period', 'administrationId'],
			array_keys($out[0]['json']['approval'])
		);
		$this->assertSame('approved', $out[0]['json']['approval']['status']);
	}//end testApprovesDraftRunStatusOnly()

	/**
	 * A non-draft run refuses: approved/posted/paid runs are booked truth.
	 *
	 * @return void
	 */
	public function testNonDraftRunRefuses(): void {
		$run = array_merge($this->draftRun(), ['status' => 'posted']);
		$objectService = $this->fakeObjectService(['PayrollRun' => [$run]]);
		$node = $this->node($objectService);

		try {
			$node->execute([$this->item(['payroll' => ['runId' => 'run-1']])], [], []);
			$this->fail('A posted run must refuse approval.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('posted', $e->getMessage());
		}

		$this->assertSame([], $objectService->saved, 'A refusal must write nothing.');
	}//end testNonDraftRunRefuses()

	/**
	 * A run id that resolves to nothing refuses loudly.
	 *
	 * @return void
	 */
	public function testMissingRunRefuses(): void {
		$objectService = $this->fakeObjectService(['PayrollRun' => []]);

		$this->expectException(RuntimeException::class);
		$this->node($objectService)->execute(
			[$this->item(['payroll' => ['runId' => 'run-404']])],
			[],
			[]
		);
	}//end testMissingRunRefuses()

	/**
	 * No resolvable run id at all refuses loudly — an approve step with
	 * nothing to approve must not report success.
	 *
	 * @return void
	 */
	public function testMissingRunIdRefuses(): void {
		$objectService = $this->fakeObjectService(['PayrollRun' => [$this->draftRun()]]);

		$this->expectException(RuntimeException::class);
		$this->node($objectService)->execute([$this->item([])], [], []);
	}//end testMissingRunIdRefuses()

	/**
	 * A literal config runId beats the item default.
	 *
	 * @return void
	 */
	public function testConfiguredRunIdWins(): void {
		$other = array_merge($this->draftRun(), ['id' => 'run-2', '@self' => ['id' => 'run-2']]);
		$objectService = $this->fakeObjectService(['PayrollRun' => [$this->draftRun(), $other]]);

		$this->node($objectService)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			['runId' => 'run-2'],
			[]
		);

		$this->assertSame('run-2', $objectService->saved[0]['uuid']);
	}//end testConfiguredRunIdWins()

	/**
	 * An empty firing returns empty and writes nothing.
	 *
	 * @return void
	 */
	public function testEmptyFiringShortCircuits(): void {
		$objectService = $this->fakeObjectService();

		$this->assertSame([], $this->node($objectService)->execute([], [], []));
		$this->assertSame([], $objectService->saved);
	}//end testEmptyFiringShortCircuits()

}//end class
