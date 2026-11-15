<?php

/**
 * Pins the failure paths of humaniq's flow nodes: a payroll run that cannot
 * be loaded or saved refuses loudly with the reason, the node listener ignores
 * events it does not handle, and the team digest is offered only where a flow
 * may run it.
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

use OCA\Humaniq\Flow\HumaniqFlowNodeListener;
use OCA\Humaniq\Flow\PayrollApproveNode;
use OCA\Humaniq\Flow\PayrollFlowNodeServices;
use OCA\Humaniq\Flow\TeamDigestNode;
use OCA\Humaniq\Service\TeamDigestComposer;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Failure paths and the availability of humaniq's flow nodes.
 */
class FlowNodeFailurePathsTest extends FlowNodeTestCase {

	/**
	 * A draft run the approve step can find.
	 *
	 * @var array<string, mixed>
	 */
	private const DRAFT_RUN = [
		'id' => 'run-1',
		'period' => '2026-05',
		'status' => 'draft',
		'@self' => ['id' => 'run-1'],
	];

	/**
	 * A failed save of the approved run refuses with the run and the reason,
	 * and keeps the original failure as the cause.
	 *
	 * @return void
	 */
	public function testAFailedApproveSaveRefusesWithTheReason(): void {
		$cause = new RuntimeException('disk full');
		$objectService = new class (self::DRAFT_RUN, $cause) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $run   The run findAll answers.
			 * @param RuntimeException     $cause What saveObject throws.
			 */
			public function __construct(
				private readonly array $run,
				private readonly RuntimeException $cause,
			) {
			}//end __construct()

			/**
			 * Any register.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Any schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * The one draft run.
			 *
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return [$this->run];
			}//end findAll()

			/**
			 * Refuse every write.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param string|null          $register      The register.
			 * @param string|null          $schema        The schema.
			 * @param string|null          $uuid          The uuid.
			 * @param bool                 $_rbac         RBAC on.
			 * @param bool                 $_multitenancy Multitenancy on.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				throw $this->cause;
			}//end saveObject()
		};

		try {
			$this->approveNode($objectService)->execute($this->call());
			$this->fail('a failed save must refuse');
		} catch (RuntimeException $e) {
			$this->assertSame('Goedkeuren van loonrun run-1 is mislukt: disk full', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAFailedApproveSaveRefusesWithTheReason()

	/**
	 * Runs that cannot be loaded refuse with the run and the reason before
	 * anything is written.
	 *
	 * @return void
	 */
	public function testRunsThatCannotBeLoadedRefuseBeforeAnyWrite(): void {
		$objectService = new class {
			/**
			 * Writes asked for.
			 *
			 * @var array<int, mixed>
			 */
			public array $saved = [];

			/**
			 * Any register.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Any schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * The store is down.
			 *
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $options = []): array {
				throw new RuntimeException('register offline');
			}//end findAll()

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param string|null          $uuid   The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved[] = $uuid;
				return $object;
			}//end saveObject()
		};

		try {
			$this->approveNode($objectService)->execute($this->call());
			$this->fail('an unloadable run must refuse');
		} catch (RuntimeException $e) {
			$this->assertSame('Loonrun run-1 kon niet geladen worden: register offline', $e->getMessage());
			$this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
		}

		$this->assertSame([], $objectService->saved);
	}//end testRunsThatCannotBeLoadedRefuseBeforeAnyWrite()

	/**
	 * An event other than the flow node registration is ignored: no node is resolved.
	 *
	 * @return void
	 */
	public function testTheListenerIgnoresOtherEvents(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		(new HumaniqFlowNodeListener($container, $this->logger()))->handle(new Event());
	}//end testTheListenerIgnoresOtherEvents()

	/**
	 * The team digest is offered to administrator and user flows, and to no other scope.
	 *
	 * @return void
	 */
	public function testTheTeamDigestIsOfferedToAdministratorAndUserFlowsOnly(): void {
		$node = new TeamDigestNode(
			l10n: $this->l10n(),
			urls: $this->urls(),
			composer: $this->createMock(TeamDigestComposer::class)
		);

		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($node->isAvailableForScope(2));
		$this->assertFalse($node->isAvailableForScope(-1));
	}//end testTheTeamDigestIsOfferedToAdministratorAndUserFlowsOnly()

	/**
	 * The approve node over an object service.
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return PayrollApproveNode
	 */
	private function approveNode(object $objectService): PayrollApproveNode {
		return new PayrollApproveNode(
			new PayrollFlowNodeServices($this->l10n(), $this->urls(), $this->container($objectService), $this->settings()),
			$this->logger()
		);
	}//end approveNode()

	/**
	 * One item naming run-1.
	 *
	 * @return FlowNodeCall
	 */
	private function call(): FlowNodeCall {
		return new FlowNodeCall(items: [['json' => ['payroll' => ['runId' => 'run-1']]]], config: [], context: []);
	}//end call()
}//end class
