<?php

/**
 * Tests for ShippedFlowPublisher.
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
 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\FlowGraphKeypaths;
use OCA\Humaniq\Service\OpenRegisterAvailability;
use OCA\Humaniq\Service\ShippedFlowPublisher;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every declared flow gets a published version, guarded, and a second run changes nothing.
 *
 * @covers \OCA\Humaniq\Service\ShippedFlowPublisher
 * @uses   \OCA\Humaniq\Service\FlowGraphKeypaths
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 */
class ShippedFlowPublisherTest extends TestCase {

	/**
	 * The temporary fragment directory.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * The nodes of the fixture flow.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const NODES = [
		['id' => 'start', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'humaniq', 'schema' => 'Onboarding']],
		['id' => 'end', 'type' => 'openregister.end', 'config' => []],
	];

	/**
	 * The edges of the fixture flow.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const EDGES = [['id' => 'e', 'from' => 'start', 'to' => 'end']];

	/**
	 * Write a fragment directory with two humaniq flows and one of another app.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/humaniq-flows-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$flow = static fn (string $name, string $app): array => ['name' => $name, 'app' => $app, 'nodes' => self::NODES, 'edges' => self::EDGES];
		$fragment = [
			'components' => [
				'schemas' => [
					'Onboarding' => [
						'slug' => 'Onboarding',
						'configuration' => ['x-openregister-flows' => [$flow('Indiensttreding', 'humaniq'), $flow('Elsewhere', 'other')]],
					],
					'Plain' => ['slug' => 'Plain'],
				],
			],
		];
		file_put_contents($this->dir . '/b.json', json_encode($fragment));
		$second = ['components' => ['schemas' => ['Offboarding' => ['configuration' => ['x-openregister-flows' => [$flow('Uitdiensttreding', 'humaniq')]]]]]];
		file_put_contents($this->dir . '/a.json', json_encode($second));
		file_put_contents($this->dir . '/c.json', 'not json');

	}//end setUp()

	/**
	 * Remove the fragment directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			unlink((string)$file);
		}

		rmdir($this->dir);

	}//end tearDown()

	/**
	 * A stored flow double.
	 *
	 * @param string $name   The flow name.
	 * @param string $schema The trigger schema.
	 * @param array  $nodes  The head nodes.
	 *
	 * @return object The flow.
	 */
	private function flow(string $name, string $schema, array $nodes=self::NODES): object {
		return new class($name, $schema, $nodes, self::EDGES) {
			/**
			 * @param string $name   Name.
			 * @param string $schema Trigger schema.
			 * @param array  $nodes  Nodes.
			 * @param array  $edges  Edges.
			 */
			public function __construct(private string $name, private string $schema, private array $nodes, private array $edges) {
			}

			/** @return string */
			public function getName(): string {
				return $this->name;
			}

			/** @return string */
			public function getTriggerSchema(): string {
				return $this->schema;
			}

			/** @return string */
			public function getUuid(): string {
				return 'uuid-' . $this->name;
			}

			/** @return array */
			public function getNodes(): array {
				return $this->nodes;
			}

			/** @return array */
			public function getEdges(): array {
				return $this->edges;
			}
		};

	}//end flow()

	/**
	 * A flow-version service double that records what it publishes.
	 *
	 * @param object|null $shippedUpdate What publishShippedUpdate returns.
	 * @param bool        $failPublish   Whether publish throws.
	 *
	 * @return object The double; its `published` and `updates` list the flow names.
	 */
	private function versions(?object $shippedUpdate=null, bool $failPublish=false): object {
		return new class($shippedUpdate, $failPublish) {
			/** @var list<string> */
			public array $published = [];

			/** @var list<string> */
			public array $updates = [];

			/**
			 * @param object|null $shippedUpdate Return of publishShippedUpdate.
			 * @param bool        $failPublish   Whether publish throws.
			 */
			public function __construct(private ?object $shippedUpdate, private bool $failPublish) {
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return object
			 */
			public function publish(object $flow): object {
				if ($this->failPublish === true) {
					throw new RuntimeException('the graph has a dead end');
				}

				$this->published[] = $flow->getName();
				return new \stdClass();
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return object|null
			 */
			public function publishShippedUpdate(object $flow): ?object {
				$this->updates[] = $flow->getName();
				return $this->shippedUpdate;
			}
		};

	}//end versions()

	/**
	 * Build the publisher over doubles.
	 *
	 * @param array<int, object>        $stored    The stored flows.
	 * @param object                    $versions  The version-service double.
	 * @param array<string, array|null> $published Published graph per flow uuid.
	 * @param LoggerInterface|null      $logger    The logger.
	 *
	 * @return ShippedFlowPublisher The publisher.
	 */
	private function publisher(array $stored, object $versions, array $published, ?LoggerInterface $logger=null): ShippedFlowPublisher {
		$mapper = new class($stored) {
			/** @var list<array{0: string, 1: int}> */
			public array $calls = [];

			/**
			 * @param array $stored Stored flows.
			 */
			public function __construct(private array $stored) {
			}

			/**
			 * @param string $app   App.
			 * @param int    $limit Limit.
			 *
			 * @return array
			 */
			public function findAllFlows(string $app, int $limit): array {
				$this->calls[] = [$app, $limit];
				return $this->stored;
			}
		};
		$graphs = new class($published) {
			/**
			 * @param array $published Graph per uuid.
			 */
			public function __construct(private array $published) {
			}

			/**
			 * @param string $uuid Flow uuid.
			 *
			 * @return array|null
			 */
			public function graphOf(string $uuid): ?array {
				return ($this->published[$uuid] ?? null);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\FlowMapper' => $mapper,
				'OCA\OpenRegister\Service\Flow\FlowVersionService' => $versions,
				'OCA\OpenRegister\Service\Flow\FlowPublishedGraph' => $graphs,
			}
		);

		return new ShippedFlowPublisher(
			$this->availability(true),
			$container,
			new FlowGraphKeypaths(),
			($logger ?? $this->createMock(LoggerInterface::class)),
			$this->dir
		);

	}//end publisher()

	/**
	 * OpenRegister availability over an app manager double.
	 *
	 * @param bool $installed Whether OpenRegister is installed.
	 *
	 * @return OpenRegisterAvailability The availability.
	 */
	private function availability(bool $installed): OpenRegisterAvailability {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		return new OpenRegisterAvailability($apps);

	}//end availability()

	/**
	 * Without OpenRegister nothing is looked up.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		$publisher = new ShippedFlowPublisher($this->availability(false), $container, new FlowGraphKeypaths(), $this->createMock(LoggerInterface::class), $this->dir);

		self::assertSame(
			[['flow' => '*', 'status' => 'skipped', 'detail' => 'OpenRegister is not installed or enabled.']],
			$publisher->publishAll()
		);

	}//end testWithoutOpenRegisterItSkips()

	/**
	 * Only this app's declarations are read, in file order, with the schema slug or key.
	 *
	 * @return void
	 */
	public function testItReadsOnlyThisAppsDeclarations(): void {
		$declared = $this->publisher([], $this->versions(), [])->declaredFlows();

		self::assertSame(
			[['Offboarding', 'Uitdiensttreding'], ['Onboarding', 'Indiensttreding']],
			array_map(static fn (array $d): array => [$d['schema'], $d['declaration']['name']], $declared)
		);

	}//end testItReadsOnlyThisAppsDeclarations()

	/**
	 * A new flow imported without a published version is published, and a
	 * second run publishes nothing.
	 *
	 * @return void
	 */
	public function testANewFlowIsPublishedAndASecondRunChangesNothing(): void {
		$flow     = $this->flow('Indiensttreding', 'Onboarding');
		$versions = $this->versions();

		$first = $this->publisher([$flow], $versions, [])->publishAll();
		self::assertSame(['Indiensttreding'], $versions->published);
		self::assertSame(
			[
				['flow' => 'Uitdiensttreding', 'status' => 'not-imported', 'detail' => 'Not imported on this instance yet.'],
				['flow' => 'Indiensttreding', 'status' => 'published', 'detail' => 'Published the shipped declaration.'],
			],
			$first
		);

		$second = $this->publisher([$flow], $versions, ['uuid-Indiensttreding' => ['nodes' => self::NODES]])->publishAll();
		self::assertSame(['Indiensttreding'], $versions->published, 'the second run must not publish again');
		self::assertSame(['Indiensttreding'], $versions->updates);
		self::assertSame('ok', $second[1]['status']);

	}//end testANewFlowIsPublishedAndASecondRunChangesNothing()

	/**
	 * A never-published head that a person edited is left for them.
	 *
	 * @return void
	 */
	public function testAnEditedUnpublishedHeadIsLeftAlone(): void {
		$edited   = $this->flow('Indiensttreding', 'Onboarding', [self::NODES[0]]);
		$versions = $this->versions();

		$reports = $this->publisher([$edited], $versions, [])->publishAll();

		self::assertSame([], $versions->published);
		self::assertSame('left-alone', $reports[1]['status']);

	}//end testAnEditedUnpublishedHeadIsLeftAlone()

	/**
	 * A flow with the same name on another schema is not this declaration.
	 *
	 * @return void
	 */
	public function testTheIdentityIncludesTheTriggerSchema(): void {
		$versions = $this->versions();

		$reports = $this->publisher([$this->flow('Indiensttreding', 'Offboarding')], $versions, [])->publishAll();

		self::assertSame([], $versions->published);
		self::assertSame('not-imported', $reports[1]['status']);

	}//end testTheIdentityIncludesTheTriggerSchema()

	/**
	 * A published flow is handed to OpenRegister's shipped-update publish.
	 *
	 * @return void
	 */
	public function testAPublishedFlowGetsTheShippedUpdate(): void {
		$versions = $this->versions(new \stdClass());

		$reports = $this->publisher(
			[$this->flow('Uitdiensttreding', 'Offboarding')],
			$versions,
			['uuid-Uitdiensttreding' => ['nodes' => []]]
		)->publishAll();

		self::assertSame(['Uitdiensttreding'], $versions->updates);
		self::assertSame([], $versions->published);
		self::assertSame(['flow' => 'Uitdiensttreding', 'status' => 'republished', 'detail' => 'Published the shipped update.'], $reports[0]);

	}//end testAPublishedFlowGetsTheShippedUpdate()

	/**
	 * A refused publish is reported and logged, and the next flow still runs.
	 *
	 * @return void
	 */
	public function testARefusedPublishIsReportedAndTheRestContinues(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('Humaniq: shipped flow "Uitdiensttreding" could not be published', ['exception' => 'the graph has a dead end']);

		$reports = $this->publisher(
			[$this->flow('Uitdiensttreding', 'Offboarding'), $this->flow('Indiensttreding', 'Onboarding')],
			$this->versions(null, true),
			['uuid-Indiensttreding' => ['nodes' => self::NODES]],
			$logger
		)->publishAll();

		self::assertSame(['flow' => 'Uitdiensttreding', 'status' => 'failed', 'detail' => 'the graph has a dead end'], $reports[0]);
		self::assertSame('ok', $reports[1]['status']);

	}//end testARefusedPublishIsReportedAndTheRestContinues()

	/**
	 * The shipped fragments declare the three HR flows next to Loonrun.
	 *
	 * @return void
	 */
	public function testTheShippedFragmentsDeclareTheHrFlows(): void {
		$publisher = new ShippedFlowPublisher(
			$this->availability(true),
			$this->createMock(ContainerInterface::class),
			new FlowGraphKeypaths(),
			$this->createMock(LoggerInterface::class)
		);

		$names = array_map(
			static fn (array $d): string => $d['schema'] . ':' . $d['declaration']['name'],
			$publisher->declaredFlows()
		);

		foreach (['Onboarding:Indiensttreding', 'Offboarding:Uitdiensttreding', 'LeaveRequest:Verlofaanvraag blijft liggen', 'PayrollRun:Loonrun'] as $expected) {
			self::assertContains($expected, $names);
		}

	}//end testTheShippedFragmentsDeclareTheHrFlows()

}//end class
