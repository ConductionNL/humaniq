<?php

/**
 * OpenRegister flow-engine test stub
 *
 * TEST-ONLY shape of the three OpenRegister flow classes humaniq's payroll
 * nodes and their listener touch (payroll-run-as-a-flow design.md D6):
 *
 * - `OCA\OpenRegister\Service\Flow\IFlowNode` — the node contract the four
 *   `humaniq.payroll-*` classes implement. Method-for-method mirror of
 *   openregister@246222d `lib/Service/Flow/IFlowNode.php` (same names, same
 *   signatures, same return types), so the standalone suite compiles against
 *   the REAL contract instead of a shape invented at the call site.
 * - `OCA\OpenRegister\Service\Flow\FlowNodeRegistry` — real-API mirror of the
 *   registration surface the event delegates to: `register()` keyed on
 *   `getId()`, first registration wins (openregister@246222d refuses
 *   duplicates rather than letting load order decide). `nodes()` is the test
 *   observation seam.
 * - `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` — mirror of the
 *   discovery event: extends the OCP Event, carries the registry,
 *   `registerNode()` delegates.
 *
 * In a real Nextcloud instance the OpenRegister app ships the real classes
 * and this file is never loaded (tests/bootstrap.php only requires it when
 * the real interface is absent). NEVER in composer.json's autoload map, so it
 * can never shadow the real classes in a live instance.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Test-stub mirror of OpenRegister's IFlowNode (openregister@246222d).
 */
interface IFlowNode {
	/**
	 * The step `type` this node answers to, unique across the fleet.
	 *
	 * @return string The type identifier.
	 */
	public function getId(): string;

	/**
	 * Human-readable name for the palette.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string;

	/**
	 * What this node does, in one sentence.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string;

	/**
	 * Absolute URL of the palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string;

	/**
	 * Whether this node is offered in the given scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool;

	/**
	 * Reject a configuration the author cannot have meant.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws \UnexpectedValueException When the configuration is unusable.
	 */
	public function validateConfig(array $config): void;

	/**
	 * Do the work: items in, items out.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata — NOT the data channel.
	 *
	 * @return array The output items.
	 */
	public function execute(array $items, array $config, array $context): array;
}//end interface

/**
 * Test-stub mirror of OpenRegister's FlowNodeRegistry registration surface
 * (openregister@246222d: `__construct(IEventDispatcher, LoggerInterface)`,
 * `register(IFlowNode): void` first-wins, `all(?int $scope): array`).
 */
class FlowNodeRegistry {

	/**
	 * Registered nodes, keyed by type id.
	 *
	 * @var array<string, IFlowNode>
	 */
	private array $nodes = [];

	/**
	 * @param \OCP\EventDispatcher\IEventDispatcher $dispatcher Unused by the mirror.
	 * @param \Psr\Log\LoggerInterface $logger Unused by the mirror.
	 */
	public function __construct(
		private readonly \OCP\EventDispatcher\IEventDispatcher $dispatcher,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Register one node type. First registration wins — the real registry
	 * refuses duplicates rather than letting app load order decide.
	 *
	 * @param IFlowNode $node The node type.
	 *
	 * @return void
	 */
	public function register(IFlowNode $node): void {
		$id = trim($node->getId());
		if ($id === '' || array_key_exists($id, $this->nodes) === true) {
			return;
		}

		$this->nodes[$id] = $node;
	}//end register()

	/**
	 * The registered nodes.
	 *
	 * @param int|null $scope Optional scope filter, mirrored but unapplied.
	 *
	 * @return array<string, IFlowNode>
	 */
	public function all(?int $scope = null): array {
		return $this->nodes;
	}//end all()

}//end class

/**
 * Test-stub mirror of OpenRegister's RegisterFlowNodesEvent.
 */
class RegisterFlowNodesEvent extends Event {

	/**
	 * @param FlowNodeRegistry $registry The registry to contribute to.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Contribute a node type.
	 *
	 * @param IFlowNode $node The node type.
	 *
	 * @return void
	 */
	public function registerNode(IFlowNode $node): void {
		$this->registry->register($node);

	}//end registerNode()

}//end class
