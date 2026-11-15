<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plumbing traits on their own: the availability checks before the
 * container is asked, what a failing load does, and which keys identify an
 * employee.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use DateTimeImmutable;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Support\ChecksFilingDeadlines;
use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCA\Humaniq\Support\LoadsAllRows;
use OCA\Humaniq\Support\ReadsEmployeeKeys;
use OCA\Humaniq\Support\ResolvesAgendaWindow;
use OCA\Humaniq\Support\ResolvesObjectService;
use OCA\Humaniq\Support\ResolvesObjectServiceThroughCaller;
use OCA\Humaniq\Support\ResolvesObjectServiceWithReload;
use OCA\Humaniq\Support\ResolvesSchemaSlug;
use OCA\Humaniq\Support\RoundsToCents;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Shared plumbing traits.
 *
 * @covers \OCA\Humaniq\Support\ChecksFilingDeadlines
 * @covers \OCA\Humaniq\Support\ConvertsRowsToArrays
 * @covers \OCA\Humaniq\Support\LoadsAllRows
 * @covers \OCA\Humaniq\Support\ReadsEmployeeKeys
 * @covers \OCA\Humaniq\Support\ResolvesAgendaWindow
 * @covers \OCA\Humaniq\Support\ResolvesObjectService
 * @covers \OCA\Humaniq\Support\ResolvesObjectServiceThroughCaller
 * @covers \OCA\Humaniq\Support\ResolvesObjectServiceWithReload
 * @covers \OCA\Humaniq\Support\ResolvesSchemaSlug
 * @covers \OCA\Humaniq\Support\RoundsToCents
 */
class SharedPlumbingTraitsTest extends TestCase {

	/**
	 * Each object service lookup refuses before it asks the container when
	 * OpenRegister is missing, with its own message, and asks it otherwise.
	 *
	 * @return void
	 */
	public function testTheLookupsCheckAvailabilityFirst(): void {
		$plain = 'humaniq requires the OpenRegister app, which is not installed on this instance.';
		foreach ([[$this->direct(...), $plain], [$this->throughCaller(...), $plain], [$this->withReload(...), $plain . ' Install and enable it, then reload.']] as [$make, $message]) {
			$untouched = $this->createMock(ContainerInterface::class);
			$untouched->expects($this->never())->method('get');
			try {
				$make($this->settings(false), $untouched)->lookup();
				$this->fail('A missing OpenRegister refuses.');
			} catch (RuntimeException $e) {
				$this->assertSame($message, $e->getMessage());
			}

			$service = new \stdClass();
			$container = $this->createMock(ContainerInterface::class);
			$container->expects($this->once())->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
			$this->assertSame($service, $make($this->settings(true), $container)->lookup());
		}
	}//end testTheLookupsCheckAvailabilityFirst()

	/**
	 * A load reads the register and schema up to the class's limit, turns
	 * rows into arrays and answers an empty list for an answer that is no list.
	 *
	 * @return void
	 */
	public function testALoadReadsUpToTheLimit(): void {
		$service = new class {

			/**
			 * @var array<int, mixed>
			 */
			public array $calls = [];

			/**
			 * @var mixed
			 */
			public mixed $answer = [['id' => 'a'], 'not a row'];

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->calls[] = $register;
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->calls[] = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options.
			 *
			 * @return mixed
			 */
			public function findAll(array $options): mixed {
				$this->calls[] = $options;
				return $this->answer;
			}//end findAll()

		};
		$loader = $this->loader($service, $this->logger());

		$this->assertSame([['id' => 'a'], []], $loader->load('Employee'));
		$this->assertSame(['humaniq', 'Employee', ['limit' => 7]], $service->calls);

		$service->answer = 'no list';
		$this->assertSame([], $loader->load('Employee'));
	}//end testALoadReadsUpToTheLimit()

	/**
	 * A failing load gives an empty list and logs under the class's own name.
	 *
	 * @return void
	 */
	public function testAFailingLoadIsLoggedAndEmpty(): void {
		$service = new class {

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				throw new RuntimeException('down');
			}//end setRegister()

		};
		$logger = $this->logger();
		$loader = $this->loader($service, $logger);

		$this->assertSame([], $loader->load('Employee'));
		$this->assertSame(['LoaderFixture: kon Employee niet laden: down'], $logger->lines);
	}//end testAFailingLoadIsLoggedAndEmpty()

	/**
	 * An employee is known by id, slug and number, and empty ones are left out.
	 *
	 * @return void
	 */
	public function testTheKeysOfAnEmployee(): void {
		$keys = new class {

			use ReadsEmployeeKeys;

			/**
			 * @param array<string, mixed> $employee The employee.
			 *
			 * @return array<int, string>
			 */
			public function read(array $employee): array {
				return array_values($this->employeeKeys($employee));
			}//end read()

			/**
			 * @param array<string, mixed> $row The row.
			 *
			 * @return string
			 */
			private function idOf(array $row): string {
				return (string)($row['id'] ?? '');
			}//end idOf()

		};

		$this->assertSame(['e1', 'jan', 'N7'], $keys->read(['id' => 'e1', '@self' => ['slug' => 'jan'], 'employeeNumber' => ' N7 ']));
		$this->assertSame(['e1'], $keys->read(['id' => 'e1', 'employeeNumber' => ' ']));
	}//end testTheKeysOfAnEmployee()

	/**
	 * Cents round per value, and a value that is no number is 0.
	 *
	 * @return void
	 */
	public function testCents(): void {
		$cents = new class {

			use RoundsToCents;

			/**
			 * @param mixed $euros The value.
			 *
			 * @return int
			 */
			public function read(mixed $euros): int {
				return self::cents($euros);
			}//end read()

		};

		$this->assertSame(1235, $cents->read('12.345'));
		$this->assertSame(0, $cents->read('twelve'));
		$this->assertSame(0, $cents->read(null));
	}//end testCents()

	/**
	 * A schema id gives its slug; no id, or a failing lookup, gives ''.
	 *
	 * @return void
	 */
	public function testTheSchemaSlug(): void {
		$schema = new class {

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return 'leave';
			}//end getSlug()

		};
		$mapper = new class($schema) {

			/**
			 * @param object $schema The schema to answer.
			 */
			public function __construct(private readonly object $schema) {
			}//end __construct()

			/**
			 * @param string $id The id.
			 *
			 * @return object
			 */
			public function find(string $id): object {
				if ($id !== '12') {
					throw new RuntimeException('none');
				}

				return $this->schema;
			}//end find()

		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		$reader = new class($container) {

			use ResolvesSchemaSlug;

			/**
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(private readonly ContainerInterface $container) {
			}//end __construct()

			/**
			 * @param string $id The schema id.
			 *
			 * @return string
			 */
			public function read(string $id): string {
				return $this->resolveSchemaSlug($id);
			}//end read()

		};

		$this->assertSame('leave', $reader->read('12'));
		$this->assertSame('', $reader->read('13'));
		$this->assertSame('', $reader->read(''));
	}//end testTheSchemaSlug()

	/**
	 * A window is two parseable dates in order, or null.
	 *
	 * @return void
	 */
	public function testTheAgendaWindow(): void {
		$reader = new class {

			use ResolvesAgendaWindow;

			/**
			 * @param string|null $from The start.
			 * @param string|null $to   The end.
			 *
			 * @return array<int, DateTimeImmutable>|null
			 */
			public function read(?string $from, ?string $to): ?array {
				return $this->window($from, $to);
			}//end read()

		};

		$window = $reader->read(' 2026-05-01T10:00 ', '2026-05-31');
		$this->assertSame(['2026-05-01', '2026-05-31'], [$window[0]->format('Y-m-d'), $window[1]->format('Y-m-d')]);
		$this->assertNull($reader->read('2026-05-31', '2026-05-01'), 'An end before the start.');
		$this->assertNull($reader->read('nonsense', '2026-05-01'), 'An unparseable date.');
	}//end testTheAgendaWindow()

	/**
	 * A filing alerts inside its window, and not once it is sent, far off or
	 * has no readable deadline.
	 *
	 * @return void
	 */
	public function testTheFilingDeadline(): void {
		$reader = new class {

			use ChecksFilingDeadlines;

			private const ALERT_WINDOW_DAYS = 7;

			/**
			 * @param array<string, mixed> $record The filing.
			 *
			 * @return bool
			 */
			public function read(array $record): bool {
				return self::deadlineNotAlerting($record);
			}//end read()

		};

		$this->assertFalse($reader->read(['deadline' => gmdate('Y-m-d', time() + 86400)]), 'Inside the window.');
		$this->assertTrue($reader->read(['deadline' => gmdate('Y-m-d', time() + (30 * 86400))]), 'Far off.');
		$this->assertTrue($reader->read(['deadline' => 'soon']), 'Unreadable.');
		$this->assertTrue($reader->read(['status' => 'verzonden', 'deadline' => gmdate('Y-m-d', time() + 86400)]), 'Sent.');
	}//end testTheFilingDeadline()

	/**
	 * Settings that answer whether OpenRegister is there.
	 *
	 * @param bool $available The answer.
	 *
	 * @return SettingsService
	 */
	private function settings(bool $available): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);

		return $settings;
	}//end settings()

	/**
	 * A logger that keeps the warnings.
	 *
	 * @return object
	 */
	private function logger(): object {
		return new class {

			/**
			 * @var list<string>
			 */
			public array $lines = [];

			/**
			 * @param string $message The line.
			 *
			 * @return void
			 */
			public function warning(string $message): void {
				$this->lines[] = $message;
			}//end warning()

		};
	}//end logger()

	/**
	 * A class with the plain lookup.
	 *
	 * @param SettingsService    $settingsService The settings.
	 * @param ContainerInterface $container       The container.
	 *
	 * @return object
	 */
	private function direct(SettingsService $settingsService, ContainerInterface $container): object {
		return new class($settingsService, $container) {

			use ResolvesObjectService;

			/**
			 * @param SettingsService    $settingsService The settings.
			 * @param ContainerInterface $container       The container.
			 */
			public function __construct(private readonly SettingsService $settingsService, private readonly ContainerInterface $container) {
			}//end __construct()

			/**
			 * @return mixed
			 */
			public function lookup(): mixed {
				return $this->objectService();
			}//end lookup()

		};
	}//end direct()

	/**
	 * A class with the lookup through its caller.
	 *
	 * @param SettingsService    $settingsService The settings.
	 * @param ContainerInterface $container       The container.
	 *
	 * @return object
	 */
	private function throughCaller(SettingsService $settingsService, ContainerInterface $container): object {
		return new class($settingsService, (object)['container' => $container]) {

			use ResolvesObjectServiceThroughCaller;

			/**
			 * @param SettingsService $settingsService The settings.
			 * @param object          $caller          The caller, with the container.
			 */
			public function __construct(private readonly SettingsService $settingsService, private readonly object $caller) {
			}//end __construct()

			/**
			 * @return mixed
			 */
			public function lookup(): mixed {
				return $this->objectService();
			}//end lookup()

		};
	}//end throughCaller()

	/**
	 * A class with the lookup that tells the admin to reload.
	 *
	 * @param SettingsService    $settingsService The settings.
	 * @param ContainerInterface $container       The container.
	 *
	 * @return object
	 */
	private function withReload(SettingsService $settingsService, ContainerInterface $container): object {
		return new class($settingsService, $container) {

			use ResolvesObjectServiceWithReload;

			/**
			 * @param SettingsService    $settingsService The settings.
			 * @param ContainerInterface $container       The container.
			 */
			public function __construct(private readonly SettingsService $settingsService, private readonly ContainerInterface $container) {
			}//end __construct()

			/**
			 * @return mixed
			 */
			public function lookup(): mixed {
				return $this->objectService();
			}//end lookup()

		};
	}//end withReload()

	/**
	 * A class that loads rows.
	 *
	 * @param object $service The object service.
	 * @param object $logger  The logger.
	 *
	 * @return LoaderFixture
	 */
	private function loader(object $service, object $logger): LoaderFixture {
		return new LoaderFixture($service, $logger);
	}//end loader()
}//end class

/**
 * A named class, so the log line's class name can be read.
 */
final class LoaderFixture {

	use ConvertsRowsToArrays;
	use LoadsAllRows;

	private const LIMIT = 7;

	/**
	 * @param object $service The object service.
	 * @param object $logger  The logger.
	 */
	public function __construct(private readonly object $service, private readonly object $logger) {
	}//end __construct()

	/**
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function load(string $schema): array {
		return $this->loadAll($schema);
	}//end load()

	/**
	 * @return object
	 */
	private function objectService(): object {
		return $this->service;
	}//end objectService()

	/**
	 * @return string
	 */
	private function register(): string {
		return 'humaniq';
	}//end register()
}//end class
