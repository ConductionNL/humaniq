<?php

/**
 * Behaviour of the payroll flow nodes that no other test pinned.
 *
 * Which workflow scopes offer a node, the palette name of the calculate,
 * ledger-post and net-pay nodes, and that a firing with several items keeps
 * every item, in order, each with its own outcome.
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\PayrollCalculateNode;
use OCA\Humaniq\Flow\PayrollFlowNodeServices;
use OCA\Humaniq\Flow\PayrollGlPostNode;
use OCA\Humaniq\Flow\PayrollNetPayNode;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\WorkflowEngine\IManager;

/**
 * Scopes, palette names and multi-item order of the payroll flow nodes.
 */
class PayrollFlowNodeBehaviourTest extends FlowNodeTestCase {

	/**
	 * The shared services over two approved runs.
	 *
	 * @return PayrollFlowNodeServices
	 */
	private function services(): PayrollFlowNodeServices {
		$runs = [
			['id' => 'run-1', 'period' => '2026-05', 'administrationId' => 'ADM-001', 'status' => 'approved', '@self' => ['id' => 'run-1']],
			['id' => 'run-2', 'period' => '2026-06', 'administrationId' => 'ADM-001', 'status' => 'approved', '@self' => ['id' => 'run-2']],
		];

		return new PayrollFlowNodeServices($this->l10n(), $this->urls(), $this->container($this->fakeObjectService(['PayrollRun' => $runs])), $this->settings());
	}//end services()

	/**
	 * A node is offered in the admin and the user scope, and nowhere else.
	 *
	 * @return void
	 */
	public function testANodeIsOfferedInTheAdminAndUserScopeOnly(): void {
		$node = new PayrollCalculateNode($this->services(), $this->logger(), $this->createMock(PayrollRunService::class));

		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($node->isAvailableForScope(99));
	}//end testANodeIsOfferedInTheAdminAndUserScopeOnly()

	/**
	 * Each node's palette name, through a pass-through translation.
	 *
	 * @return void
	 */
	public function testThePaletteNames(): void {
		$this->assertSame('Calculate payroll run', (new PayrollCalculateNode($this->services(), $this->logger(), $this->createMock(PayrollRunService::class)))->getDisplayName());
		$this->assertSame('Post payroll run to ledger', (new PayrollGlPostNode($this->services(), $this->logger(), $this->createMock(PayrollGLPostService::class)))->getDisplayName());
		$this->assertSame('Prepare net pay batch', (new PayrollNetPayNode($this->services(), $this->logger(), $this->createMock(PayrollNetPayService::class)))->getDisplayName());
	}//end testThePaletteNames()

	/**
	 * Two runs in: two items out, in order, each with its own run's outcome.
	 *
	 * @return void
	 */
	public function testTheLedgerPostKeepsEveryItemInOrder(): void {
		$service = $this->createMock(PayrollGLPostService::class);
		$service->method('postRun')->willReturnCallback(static fn (array $run): array => ['runId' => $run['id'], 'status' => 'posted']);

		$out = (new PayrollGlPostNode($this->services(), $this->logger(), $service))->execute(new FlowNodeCall(items: $this->twoRuns(), config: [], context: []));

		$this->assertSame(['run-2', 'run-1'], array_column(array_column(array_column($out, 'json'), 'glpost'), 'runId'));
		$this->assertSame(['b', 'a'], array_column(array_column($out, 'json'), 'tag'));
	}//end testTheLedgerPostKeepsEveryItemInOrder()

	/**
	 * Two runs in: two items out, in order, each with its own run's outcome.
	 *
	 * @return void
	 */
	public function testTheNetPayKeepsEveryItemInOrder(): void {
		$service = $this->createMock(PayrollNetPayService::class);
		$service->method('processRun')->willReturnCallback(static fn (array $run): array => ['runId' => $run['id'], 'status' => 'created']);

		$out = (new PayrollNetPayNode($this->services(), $this->logger(), $service))->execute(new FlowNodeCall(items: $this->twoRuns(), config: [], context: []));

		$this->assertSame(['run-2', 'run-1'], array_column(array_column(array_column($out, 'json'), 'netpay'), 'runId'));
		$this->assertSame(['b', 'a'], array_column(array_column($out, 'json'), 'tag'));
	}//end testTheNetPayKeepsEveryItemInOrder()

	/**
	 * Two periods in: two items out, in order, each with its own period's outcome.
	 *
	 * @return void
	 */
	public function testTheCalculationKeepsEveryItemInOrder(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->method('runFor')->willReturnCallback(static fn (string $period): array => ['runId' => 'run-' . $period, 'period' => $period, 'status' => 'calculated']);

		$out = (new PayrollCalculateNode($this->services(), $this->logger(), $service))->execute(new FlowNodeCall(
			items: [$this->item(['maand' => '2026-06', 'tag' => 'b']), $this->item(['maand' => '2026-05', 'tag' => 'a'])],
			config: ['period' => '{{ maand }}'],
			context: []
		));

		$this->assertSame(['2026-06', '2026-05'], array_column(array_column(array_column($out, 'json'), 'payroll'), 'period'));
		$this->assertSame(['b', 'a'], array_column(array_column($out, 'json'), 'tag'));
	}//end testTheCalculationKeepsEveryItemInOrder()

	/**
	 * Two items naming run-2 and run-1, in that order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function twoRuns(): array {
		return [
			$this->item(['payroll' => ['runId' => 'run-2'], 'tag' => 'b']),
			$this->item(['payroll' => ['runId' => 'run-1'], 'tag' => 'a']),
		];
	}//end twoRuns()
}//end class
