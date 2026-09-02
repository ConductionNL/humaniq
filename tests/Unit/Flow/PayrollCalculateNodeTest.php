<?php

/**
 * Unit tests for PayrollCalculateNode — outcome routing, period defaulting
 * and the loud refusal of committed runs (REQ-PRF-002).
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\PayrollCalculateNode;
use OCA\Humaniq\Service\PayrollRunService;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for the calculate adapter.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
 */
class PayrollCalculateNodeTest extends FlowNodeTestCase {

	/**
	 * Build the node around a PayrollRunService double.
	 *
	 * @param PayrollRunService $service The service double.
	 *
	 * @return PayrollCalculateNode
	 */
	private function node(PayrollRunService $service): PayrollCalculateNode {
		return new PayrollCalculateNode(
			$this->l10n(),
			$this->urls(),
			$this->container(),
			$this->settings(),
			$this->logger(),
			$service
		);
	}//end node()

	/**
	 * A service outcome in the real vocabulary (PayrollRunService::outcome()).
	 *
	 * @param string $status The outcome status.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $status): array {
		return [
			'runId' => 'run-1',
			'period' => '2026-05',
			'administrationId' => 'ADM-001',
			'status' => $status,
			'message' => 'msg',
			'computed' => [],
			'skipped' => [],
			'totals' => null,
		];
	}//end outcome()

	/**
	 * The node id is the shipped flow's vocabulary.
	 *
	 * @return void
	 */
	public function testIdentity(): void {
		$service = $this->createMock(PayrollRunService::class);
		$node = $this->node($service);

		$this->assertSame('humaniq.payroll-calculate', $node->getId());
		$this->assertNotSame('', $node->getDisplayName());
		$this->assertNotSame('', $node->getDescription());
	}//end testIdentity()

	/**
	 * A configured period is rendered against the item and handed to the
	 * service; the outcome travels on the item under `payroll`.
	 *
	 * @return void
	 */
	public function testConfiguredPeriodAndOutcomePassThrough(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->expects($this->once())
			->method('runFor')
			->with('2026-05', 'ADM-002', true)
			->willReturn($this->outcome('calculated'));

		$out = $this->node($service)->execute(
			[$this->item(['maand' => '2026-05'])],
			['period' => '{{ maand }}', 'administrationId' => 'ADM-002'],
			[]
		);

		$this->assertCount(1, $out);
		$this->assertSame('calculated', $out[0]['json']['payroll']['status']);
		$this->assertSame('run-1', $out[0]['json']['payroll']['runId']);
	}//end testConfiguredPeriodAndOutcomePassThrough()

	/**
	 * No configured period falls back to the current month UTC.
	 *
	 * @return void
	 */
	public function testDefaultPeriodIsCurrentMonth(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->expects($this->once())
			->method('runFor')
			->with(gmdate('Y-m'), null, true)
			->willReturn($this->outcome('exists'));

		$out = $this->node($service)->execute([$this->item([])], [], []);

		$this->assertSame('exists', $out[0]['json']['payroll']['status']);
	}//end testDefaultPeriodIsCurrentMonth()

	/**
	 * `recalculate: false` in the config reaches the service.
	 *
	 * @return void
	 */
	public function testRecalculateFalseIsForwarded(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->expects($this->once())
			->method('runFor')
			->with(gmdate('Y-m'), null, false)
			->willReturn($this->outcome('exists'));

		$this->node($service)->execute([$this->item([])], ['recalculate' => false], []);
	}//end testRecalculateFalseIsForwarded()

	/**
	 * `refused-not-draft` throws: a run that is booked truth must not carry
	 * a success-shaped item into the review task.
	 *
	 * @return void
	 */
	public function testRefusedNotDraftThrows(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->method('runFor')->willReturn($this->outcome('refused-not-draft'));

		$this->expectException(RuntimeException::class);
		$this->node($service)->execute([$this->item([])], [], []);
	}//end testRefusedNotDraftThrows()

	/**
	 * `failed` throws for the same reason.
	 *
	 * @return void
	 */
	public function testFailedThrows(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->method('runFor')->willReturn($this->outcome('failed'));

		$this->expectException(RuntimeException::class);
		$this->node($service)->execute([$this->item([])], [], []);
	}//end testFailedThrows()

	/**
	 * An empty firing returns empty without touching the service.
	 *
	 * @return void
	 */
	public function testEmptyFiringShortCircuits(): void {
		$service = $this->createMock(PayrollRunService::class);
		$service->expects($this->never())->method('runFor');

		$this->assertSame([], $this->node($service)->execute([], [], []));
	}//end testEmptyFiringShortCircuits()

	/**
	 * A malformed literal period is refused at save time.
	 *
	 * @return void
	 */
	public function testValidateConfigRefusesMalformedLiteralPeriod(): void {
		$node = $this->node($this->createMock(PayrollRunService::class));

		$this->expectException(UnexpectedValueException::class);
		$node->validateConfig(['period' => '05-2026']);
	}//end testValidateConfigRefusesMalformedLiteralPeriod()

	/**
	 * A templated period and an absent period are save-time acceptable.
	 *
	 * @return void
	 */
	public function testValidateConfigAcceptsTemplateAndAbsent(): void {
		$node = $this->node($this->createMock(PayrollRunService::class));

		$node->validateConfig([]);
		$node->validateConfig(['period' => '{{ maand }}']);
		$node->validateConfig(['period' => '2026-05']);

		$this->addToAssertionCount(1);
	}//end testValidateConfigAcceptsTemplateAndAbsent()

}//end class
