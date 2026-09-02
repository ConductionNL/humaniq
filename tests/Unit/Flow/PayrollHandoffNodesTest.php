<?php

/**
 * Unit tests for the glpost and netpay adapter nodes — outcome routing
 * including the documented shillinq degradation (REQ-PRF-004).
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\PayrollGlPostNode;
use OCA\Humaniq\Flow\PayrollNetPayNode;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use RuntimeException;

/**
 * Tests for the shillinq handoff adapters.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-004
 */
class PayrollHandoffNodesTest extends FlowNodeTestCase {

	/**
	 * An approved PayrollRun row.
	 *
	 * @return array<string, mixed>
	 */
	private function approvedRun(): array {
		return [
			'id' => 'run-1',
			'period' => '2026-05',
			'administrationId' => 'ADM-001',
			'status' => 'approved',
			'@self' => ['id' => 'run-1'],
		];
	}//end approvedRun()

	/**
	 * Build the glpost node around a service double.
	 *
	 * @param PayrollGLPostService $service The service double.
	 *
	 * @return PayrollGlPostNode
	 */
	private function glPostNode(PayrollGLPostService $service): PayrollGlPostNode {
		return new PayrollGlPostNode(
			$this->l10n(),
			$this->urls(),
			$this->container($this->fakeObjectService(['PayrollRun' => [$this->approvedRun()]])),
			$this->settings(),
			$this->logger(),
			$service
		);
	}//end glPostNode()

	/**
	 * Build the netpay node around a service double.
	 *
	 * @param PayrollNetPayService $service The service double.
	 *
	 * @return PayrollNetPayNode
	 */
	private function netPayNode(PayrollNetPayService $service): PayrollNetPayNode {
		return new PayrollNetPayNode(
			$this->l10n(),
			$this->urls(),
			$this->container($this->fakeObjectService(['PayrollRun' => [$this->approvedRun()]])),
			$this->settings(),
			$this->logger(),
			$service
		);
	}//end netPayNode()

	/**
	 * The node ids are the shipped flow's vocabulary.
	 *
	 * @return void
	 */
	public function testIdentities(): void {
		$glpost = $this->glPostNode($this->createMock(PayrollGLPostService::class));
		$netpay = $this->netPayNode($this->createMock(PayrollNetPayService::class));

		$this->assertSame('humaniq.payroll-glpost', $glpost->getId());
		$this->assertSame('humaniq.payroll-netpay', $netpay->getId());
	}//end testIdentities()

	/**
	 * A posted journal travels with the item, and the service received the
	 * loaded run.
	 *
	 * @return void
	 */
	public function testGlPostOutcomePassThrough(): void {
		$service = $this->createMock(PayrollGLPostService::class);
		$service->expects($this->once())
			->method('postRun')
			->with($this->callback(fn (array $run): bool => ($run['id'] ?? '') === 'run-1'))
			->willReturn(['runId' => 'run-1', 'status' => 'posted', 'message' => 'ok']);

		$out = $this->glPostNode($service)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);

		$this->assertSame('posted', $out[0]['json']['glpost']['status']);
	}//end testGlPostOutcomePassThrough()

	/**
	 * Absent shillinq is the service's documented degradation, not a step
	 * failure: the flow continues and the outcome names the skip.
	 *
	 * @return void
	 */
	public function testGlPostSkippedNoShillinqContinues(): void {
		$service = $this->createMock(PayrollGLPostService::class);
		$service->method('postRun')
			->willReturn(['runId' => 'run-1', 'status' => 'skipped-no-shillinq', 'message' => 'geen shillinq']);

		$out = $this->glPostNode($service)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);

		$this->assertSame('skipped-no-shillinq', $out[0]['json']['glpost']['status']);
	}//end testGlPostSkippedNoShillinqContinues()

	/**
	 * A failed journal throws so the step's onError policy decides.
	 *
	 * @return void
	 */
	public function testGlPostFailedThrows(): void {
		$service = $this->createMock(PayrollGLPostService::class);
		$service->method('postRun')
			->willReturn(['runId' => 'run-1', 'status' => 'failed', 'message' => 'onbalans']);

		$this->expectException(RuntimeException::class);
		$this->glPostNode($service)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);
	}//end testGlPostFailedThrows()

	/**
	 * A created batch travels with the item.
	 *
	 * @return void
	 */
	public function testNetPayOutcomePassThrough(): void {
		$service = $this->createMock(PayrollNetPayService::class);
		$service->expects($this->once())
			->method('processRun')
			->with($this->callback(fn (array $run): bool => ($run['id'] ?? '') === 'run-1'))
			->willReturn(['runId' => 'run-1', 'status' => 'created', 'message' => 'ok']);

		$out = $this->netPayNode($service)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);

		$this->assertSame('created', $out[0]['json']['netpay']['status']);
	}//end testNetPayOutcomePassThrough()

	/**
	 * A failed batch throws; the skip degradation continues.
	 *
	 * @return void
	 */
	public function testNetPayOutcomeRouting(): void {
		$service = $this->createMock(PayrollNetPayService::class);
		$service->method('processRun')
			->willReturn(['runId' => 'run-1', 'status' => 'skipped-no-shillinq', 'message' => 'geen shillinq']);

		$out = $this->netPayNode($service)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);
		$this->assertSame('skipped-no-shillinq', $out[0]['json']['netpay']['status']);

		$failing = $this->createMock(PayrollNetPayService::class);
		$failing->method('processRun')
			->willReturn(['runId' => 'run-1', 'status' => 'failed', 'message' => 'IBAN ontbreekt']);

		$this->expectException(RuntimeException::class);
		$this->netPayNode($failing)->execute(
			[$this->item(['payroll' => ['runId' => 'run-1']])],
			[],
			[]
		);
	}//end testNetPayOutcomeRouting()

	/**
	 * Empty firings return empty without touching either service.
	 *
	 * @return void
	 */
	public function testEmptyFiringsShortCircuit(): void {
		$glService = $this->createMock(PayrollGLPostService::class);
		$glService->expects($this->never())->method('postRun');
		$netService = $this->createMock(PayrollNetPayService::class);
		$netService->expects($this->never())->method('processRun');

		$this->assertSame([], $this->glPostNode($glService)->execute([], [], []));
		$this->assertSame([], $this->netPayNode($netService)->execute([], [], []));
	}//end testEmptyFiringsShortCircuit()

}//end class
