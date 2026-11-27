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
 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\PayrollApproveNode;
use OCA\Humaniq\Flow\PayrollFlowNodeServices;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use RuntimeException;

/**
 * Tests for the approve node.
 *
 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
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
			new PayrollFlowNodeServices($this->l10n(), $this->urls(), $this->container($objectService), $this->settings()),
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

		$out = $this->node($objectService)->execute(new FlowNodeCall(
			items: [$this->item(['payroll' => ['runId' => 'run-1']])],
			config: [],
			context: []
		));

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
	 * The approval is written under the shared internal-write marker, so the
	 * pre-save guard lets the flow's own step through once the flow is
	 * adopted, and the marker is released afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	public function testApprovalIsWrittenUnderTheInternalWriteMarker(): void {
		$marker = new InternalWriteMarker();
		$objectService = new class($this->draftRun(), $marker) {

			/**
			 * Whether each save ran marked.
			 *
			 * @var list<bool>
			 */
			public array $seen = [];

			/**
			 * @param array<string, mixed> $run    The stored run.
			 * @param InternalWriteMarker  $marker The shared marker.
			 */
			public function __construct(private array $run, private InternalWriteMarker $marker) {
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $options The query.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options=[]): array {
				return [$this->run];
			}

			/**
			 * @param mixed ...$args The save arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(...$args): array {
				$this->seen[] = $this->marker->isInternal();
				return $this->run;
			}
		};
		$node = new PayrollApproveNode(
			new PayrollFlowNodeServices($this->l10n(), $this->urls(), $this->container($objectService), $this->settings(), $marker),
			$this->logger()
		);

		$node->execute(new FlowNodeCall(items: [$this->item(['payroll' => ['runId' => 'run-1']])], config: [], context: []));

		$this->assertSame([true], $objectService->seen);
		$this->assertFalse($marker->isInternal());
	}//end testApprovalIsWrittenUnderTheInternalWriteMarker()

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
			$node->execute(new FlowNodeCall(items: [$this->item(['payroll' => ['runId' => 'run-1']])], config: [], context: []));
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
		$this->node($objectService)->execute(new FlowNodeCall(
			items: [$this->item(['payroll' => ['runId' => 'run-404']])],
			config: [],
			context: []
		));
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
		$this->node($objectService)->execute(new FlowNodeCall(items: [$this->item([])], config: [], context: []));
	}//end testMissingRunIdRefuses()

	/**
	 * A literal config runId beats the item default.
	 *
	 * @return void
	 */
	public function testConfiguredRunIdWins(): void {
		$other = array_merge($this->draftRun(), ['id' => 'run-2', '@self' => ['id' => 'run-2']]);
		$objectService = $this->fakeObjectService(['PayrollRun' => [$this->draftRun(), $other]]);

		$this->node($objectService)->execute(new FlowNodeCall(
			items: [$this->item(['payroll' => ['runId' => 'run-1']])],
			config: ['runId' => 'run-2'],
			context: []
		));

		$this->assertSame('run-2', $objectService->saved[0]['uuid']);
	}//end testConfiguredRunIdWins()

	/**
	 * An empty firing returns empty and writes nothing.
	 *
	 * @return void
	 */
	public function testEmptyFiringShortCircuits(): void {
		$objectService = $this->fakeObjectService();

		$this->assertSame([], $this->node($objectService)->execute(new FlowNodeCall(items: [], config: [], context: [])));
		$this->assertSame([], $objectService->saved);
	}//end testEmptyFiringShortCircuits()

}//end class
