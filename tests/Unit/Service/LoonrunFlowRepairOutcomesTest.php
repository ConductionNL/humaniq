<?php

/**
 * Unit tests for what LoonrunFlowRepairService::repair() answers and does.
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
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Every answer of the repair, and the calls it makes when it republishes.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */
class LoonrunFlowRepairOutcomesTest extends TestCase {

	/**
	 * The calls the doubles received, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The shipped Loonrun declaration.
	 *
	 * @return array<string, mixed>
	 */
	private static function declaration(): array {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/hr-objects.json'), true);

		return $fragment['components']['schemas'][LoonrunFlowRepairService::FLOW_TRIGGER_SCHEMA]['configuration']['x-openregister-flows'][0];
	}//end declaration()

	/**
	 * The shipped nodes with the v1 outcome keypaths.
	 *
	 * @return array<int, mixed>
	 */
	private static function v1Nodes(): array {
		$keypaths = new FlowGraphKeypaths();
		$nodes = (array)self::declaration()['nodes'];

		return $keypaths->withV1OutcomePaths($nodes, $keypaths->outcomeKeys($nodes));
	}//end v1Nodes()

	/**
	 * A flow entity double.
	 *
	 * @param string           $status The lifecycle status.
	 * @param array<int, mixed> $nodes  The head nodes.
	 *
	 * @return object
	 */
	private function flow(string $status, array $nodes): object {
		return new class($status, $nodes) {
			/**
			 * @param string            $status The lifecycle status.
			 * @param array<int, mixed> $nodes  The head nodes.
			 */
			public function __construct(private string $status, public array $nodes) {
			}

			public function getUuid(): string {
				return 'flow-1';
			}

			public function getName(): string {
				return LoonrunFlowRepairService::FLOW_NAME;
			}

			public function getTriggerSchema(): string {
				return LoonrunFlowRepairService::FLOW_TRIGGER_SCHEMA;
			}

			public function getLifecycleStatus(): string {
				return $this->status;
			}

			public function getVersion(): int {
				return 3;
			}

			public function getNodes(): array {
				return $this->nodes;
			}

			public function setNodes(array $nodes): void {
				$this->nodes = $nodes;
			}
		};
	}//end flow()

	/**
	 * The repair over doubles.
	 *
	 * @param bool                      $available Whether OpenRegister is there.
	 * @param object|null               $flow      The stored flow, or null.
	 * @param array<string, mixed>|null $published The published graph, or null.
	 *
	 * @return LoonrunFlowRepairService
	 */
	private function service(bool $available, ?object $flow, ?array $published): LoonrunFlowRepairService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$test = $this;
		$services = [
			'OCA\OpenRegister\Db\FlowMapper' => new class($test, $flow) {
				public function __construct(private LoonrunFlowRepairOutcomesTest $test, private ?object $flow) {
				}

				public function findAllFlows(string $app, int $limit): array {
					$this->test->record('findAllFlows:' . $app . ':' . $limit);
					return ($this->flow === null ? [] : [$this->flow]);
				}

				public function update(object $flow): void {
					$this->test->record('update');
				}
			},
			'OCA\OpenRegister\Service\Flow\FlowPublishedGraph' => new class($test, $published) {
				public function __construct(private LoonrunFlowRepairOutcomesTest $test, private ?array $published) {
				}

				public function graphOf(string $uuid): ?array {
					$this->test->record('graphOf:' . $uuid);
					return $this->published;
				}
			},
			'OCA\OpenRegister\Service\Flow\FlowVersionService' => new class($test) {
				public function __construct(private LoonrunFlowRepairOutcomesTest $test) {
				}

				public function createDraft(object $flow): void {
					$this->test->record('createDraft');
				}

				public function publish(object $flow, ?string $publishedBy): void {
					$this->test->record('publish');
				}
			},
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new LoonrunFlowRepairService($settings, $container, new FlowGraphKeypaths(), new NullLogger());
	}//end service()

	/**
	 * Record one call.
	 *
	 * @param string $call The call.
	 *
	 * @return void
	 */
	public function record(string $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Each way the repair stops before it publishes.
	 *
	 * @return void
	 */
	public function testEveryStopKeepsItsAnswer(): void {
		$shipped = self::declaration();
		$v1 = ['nodes' => self::v1Nodes(), 'edges' => $shipped['edges']];

		self::assertSame(['status' => 'skipped', 'detail' => 'OpenRegister is not installed or enabled.'], $this->service(false, null, null)->repair());
		self::assertSame(['status' => 'skipped', 'detail' => 'The shipped Loonrun flow is not imported on this instance.'], $this->service(true, null, null)->repair());
		self::assertSame(['status' => 'skipped', 'detail' => 'Loonrun has no published version. The corrected head publishes on adoption.'], $this->service(true, $this->flow('published', []), null)->repair());
		self::assertSame(['status' => 'ok', 'detail' => 'The published Loonrun gate already reads the record keypath. Nothing to do.'], $this->service(true, $this->flow('published', []), ['nodes' => $shipped['nodes'], 'edges' => $shipped['edges']])->repair());
		self::assertSame(['status' => 'left-alone', 'detail' => 'The published Loonrun was changed after import. Fix the gate condition to read json.review.outcome in the flow builder, then publish.'], $this->service(true, $this->flow('published', []), ['nodes' => $v1['nodes'], 'edges' => []])->repair());
		self::assertSame(['status' => 'left-alone', 'detail' => 'Loonrun has an open draft. Publishing now would ship an unfinished edit; publish the corrected gate from the builder.'], $this->service(true, $this->flow('draft', []), $v1)->repair());
		self::assertNotContains('createDraft', $this->calls);
	}//end testEveryStopKeepsItsAnswer()

	/**
	 * An untouched v1 publication is republished from the corrected head;
	 * a head that still carries the v1 keypath is rewritten first.
	 *
	 * @return void
	 */
	public function testAnUntouchedV1IsRepublished(): void {
		$shipped = self::declaration();
		$v1 = ['nodes' => self::v1Nodes(), 'edges' => $shipped['edges']];

		$clean = $this->flow('published', $shipped['nodes']);
		self::assertSame(['status' => 'republished', 'detail' => 'Published Loonrun v3 with the gate reading the record keypath.'], $this->service(true, $clean, $v1)->repair());
		self::assertSame(['findAllFlows:humaniq:500', 'graphOf:flow-1', 'createDraft', 'publish'], $this->calls);

		$this->calls = [];
		$stale = $this->flow('published', self::v1Nodes());
		self::assertSame('republished', $this->service(true, $stale, $v1)->repair()['status']);
		self::assertSame(['findAllFlows:humaniq:500', 'graphOf:flow-1', 'createDraft', 'update', 'publish'], $this->calls);
		self::assertSame($shipped['nodes'], $stale->nodes);
	}//end testAnUntouchedV1IsRepublished()
}//end class
