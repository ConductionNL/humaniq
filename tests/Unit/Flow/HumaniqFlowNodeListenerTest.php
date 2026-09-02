<?php

/**
 * Unit tests for HumaniqFlowNodeListener — all four nodes register, and one
 * broken node is skipped rather than fatal (REQ-PRF-001).
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

use OCA\Humaniq\Flow\HumaniqFlowNodeListener;
use OCA\Humaniq\Flow\PayrollApproveNode;
use OCA\Humaniq\Flow\PayrollCalculateNode;
use OCA\Humaniq\Flow\PayrollGlPostNode;
use OCA\Humaniq\Flow\PayrollNetPayNode;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\PayrollNetPayService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests for the node registration listener.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */
class HumaniqFlowNodeListenerTest extends FlowNodeTestCase {

	/**
	 * Build one real node instance for the container double.
	 *
	 * @param string $nodeClass The node class.
	 *
	 * @return object The node.
	 */
	private function buildNode(string $nodeClass): object {
		$common = [
			$this->l10n(),
			$this->urls(),
			$this->container($this->fakeObjectService()),
			$this->settings(),
			$this->logger(),
		];

		return match ($nodeClass) {
			PayrollCalculateNode::class => new PayrollCalculateNode(...array_merge($common, [$this->createMock(PayrollRunService::class)])),
			PayrollApproveNode::class => new PayrollApproveNode(...$common),
			PayrollGlPostNode::class => new PayrollGlPostNode(...array_merge($common, [$this->createMock(PayrollGLPostService::class)])),
			PayrollNetPayNode::class => new PayrollNetPayNode(...array_merge($common, [$this->createMock(PayrollNetPayService::class)])),
			default => throw new RuntimeException('Unexpected node class ' . $nodeClass),
		};
	}//end buildNode()

	/**
	 * A RegisterFlowNodesEvent capture: `registerNode()` is overridden to
	 * record ids, so the test observes the SAME call the real event receives
	 * and runs unchanged against both the stub and the real OpenRegister
	 * classes (whose registry constructor is not this test's concern).
	 *
	 * @return RegisterFlowNodesEvent The capturing event.
	 */
	private function capturingEvent(): RegisterFlowNodesEvent {
		return new class extends RegisterFlowNodesEvent {
			/**
			 * The registered node ids, in registration order.
			 *
			 * @var array<int, string>
			 */
			public array $registered = [];

			/**
			 * Deliberately does not call the parent constructor: the parent's
			 * registry is never touched because registerNode() is overridden.
			 */
			public function __construct() {

			}//end __construct()

			/**
			 * @param IFlowNode $node The node type.
			 *
			 * @return void
			 */
			public function registerNode(IFlowNode $node): void {
				$this->registered[] = $node->getId();

			}//end registerNode()
		};
	}//end capturingEvent()

	/**
	 * All four payroll nodes land on the registry under their ids.
	 *
	 * @return void
	 */
	public function testRegistersAllFourNodes(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class): object => $this->buildNode($class));

		$event = $this->capturingEvent();
		$listener = new HumaniqFlowNodeListener($container, $this->logger());
		$listener->handle($event);

		$this->assertSame(
			[
				'humaniq.payroll-calculate',
				'humaniq.payroll-approve',
				'humaniq.payroll-glpost',
				'humaniq.payroll-netpay',
			],
			$event->registered
		);
	}//end testRegistersAllFourNodes()

	/**
	 * One unresolvable node is logged and skipped; the others still register.
	 *
	 * @return void
	 */
	public function testBrokenNodeIsSkippedNotFatal(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class): object {
				if ($class === PayrollApproveNode::class) {
					throw new RuntimeException('unresolvable dependency');
				}

				return $this->buildNode($class);
			}
		);

		$event = $this->capturingEvent();
		$listener = new HumaniqFlowNodeListener($container, $this->logger());
		$listener->handle($event);

		$this->assertNotContains('humaniq.payroll-approve', $event->registered);
		$this->assertCount(3, $event->registered);
	}//end testBrokenNodeIsSkippedNotFatal()

}//end class
