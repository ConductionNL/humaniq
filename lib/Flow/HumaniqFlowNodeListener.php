<?php

/**
 * Humaniq Flow Node Listener
 *
 * Presents humaniq's payroll orchestration steps to OpenRegister's flow
 * engine (payroll-run-as-a-flow, REQ-PRF-001). ADR-065: OpenRegister owns the
 * flow engine and no leaf app grows a second one. humaniq does not keep one —
 * it CONTRIBUTES what its payroll pipeline can do, which is what
 * FlowNodeRegistry is built for and what dossiq and hermiq already do.
 *
 * Nodes are resolved from a class-string list, not injected one per
 * constructor parameter (the DossiqFlowNodeListener rationale): adding a node
 * stays one line and this class's own dependencies stay at two. A node that
 * cannot be constructed is logged and SKIPPED rather than aborting the loop —
 * one unresolvable dependency must not cost the other nodes their place in
 * the catalogue, and a missing node is visible (the flow editor simply does
 * not offer it) where a failed registration is not.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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

namespace OCA\Humaniq\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers the humaniq payroll nodes on OpenRegister's flow catalogue.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress MissingDependency Every class in NODES implements
 *     OpenRegister's IFlowNode, which is suppressed as an undefined class in
 *     psalm.xml (cross-app, runtime-loaded), so psalm cannot resolve the
 *     dependency chain. The dossiq DossiqFlowNodeListener precedent.
 * @psalm-suppress InvalidConstantAssignmentValue Every class listed in NODES
 *     is a real class-string, but with IFlowNode unresolvable psalm reads the
 *     ::class literals as mixed and rejects the narrowing. The declared type
 *     is the contract this list must satisfy and is kept deliberately.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */
class HumaniqFlowNodeListener implements IEventListener {

	/**
	 * The nodes humaniq contributes, in pipeline order.
	 *
	 * @var array<int, class-string>
	 *
	 * @psalm-suppress MissingDependency Every class here implements
	 *     OpenRegister's IFlowNode, suppressed as undefined in psalm.xml.
	 * @psalm-suppress InvalidConstantAssignmentValue With IFlowNode
	 *     unresolvable psalm reads the ::class literals as mixed and rejects
	 *     the narrowing; the declared type is the contract, kept deliberately.
	 */
	private const NODES = [
		PayrollCalculateNode::class,
		PayrollApproveNode::class,
		PayrollGlPostNode::class,
		PayrollNetPayNode::class,
	];

	/**
	 * @param ContainerInterface $container Resolves each node.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Register the humaniq nodes on the catalogue.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterFlowNodesEvent === false) {
			return;
		}

		foreach (self::NODES as $nodeClass) {
			try {
				$node = $this->container->get($nodeClass);
			} catch (Throwable $e) {
				$this->logger->warning(
					'humaniq: flow node ' . $nodeClass . ' kon niet geregistreerd worden: ' . $e->getMessage(),
					['app' => 'humaniq', 'exception' => $e]
				);
				continue;
			}

			$event->registerNode($node);
		}

	}//end handle()

	/**
	 * The node classes this listener registers — the declaration test's
	 * anchor, so the shipped flow JSON and the palette cannot drift apart
	 * (REQ-PRF-005).
	 *
	 * @return array<int, class-string>
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	public static function nodeClasses(): array {
		return self::NODES;
	}//end nodeClasses()

}//end class
