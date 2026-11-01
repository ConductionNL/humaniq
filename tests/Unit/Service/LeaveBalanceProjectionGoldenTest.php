<?php

/**
 * Golden test for LeaveBalanceProjectionService.
 *
 * Runs projectForRequest() and recomputeAll() over fixed balances, requests,
 * leave types, working patterns and a working calendar, with a recording
 * ObjectService and logger. The cases take every exit of the per-employee
 * recompute: no balance, a blank employee or type, a type that draws no
 * balance, hours that cannot be derived, an unchanged balance, a failed
 * write, a request across New Year, lapses and carry-over, several
 * employees and types, and an unread calendar. Every write, every log line
 * and every return value is compared with
 * tests/fixtures/golden/leave-balance-projection.json. Set RECORD_GOLDEN=1
 * to rewrite that file.
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
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\Humaniq\Service\LeaveTypeResolver;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WorkingCalendarReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden cases for the leave balance projection.
 *
 * @covers \OCA\Humaniq\Service\LeaveBalanceProjectionService
 * @uses   \OCA\Humaniq\Service\LeaveAllocationCalculator
 * @uses   \OCA\Humaniq\Service\LeaveHoursCalculator
 * @uses   \OCA\Humaniq\Service\LeaveDayCosts
 * @uses   \OCA\Humaniq\Service\LeaveTypeResolver
 * @uses   \OCA\Humaniq\Service\WorkingHoursService
 * @uses   \OCA\Humaniq\Service\LeaveProjectionInputs
 * @uses   \OCA\Humaniq\Service\LeaveBalanceScope
 *
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */
class LeaveBalanceProjectionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/leave-balance-projection.json';

	/**
	 * Every write and log line of the current case, in order.
	 *
	 * @var list<mixed>
	 */
	public array $events = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = json_encode($this->observe(self::scenarios()[$case]), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Run one scenario: each call with its return value, then every event.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $s): array {
		$this->events = [];
		$store = $this->store($s['rows'], ($s['failWrite'] ?? ''));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$calendar = null;
		if (array_key_exists('calendar', $s) === true) {
			$calendar = $this->createMock(WorkingCalendarReader::class);
			$calendar->method('nonWorkingDates')->willReturnCallback(
				function ($from, $to) use ($s): array {
					$this->events[] = ['calendar' => [$from->format('Y-m-d'), $to->format('Y-m-d')]];
					return ['dates' => $s['calendar'], 'resolved' => ($s['calendar'] !== null), 'reason' => 'golden'];
				}
			);
		}

		$service = new LeaveBalanceProjectionService($container, $settings, $this->logger(), new LeaveTypeResolver(), $calendar);
		$results = [];
		foreach ($s['calls'] as $call) {
			if ($call[0] === 'all') {
				$results[] = $service->recomputeAll($call[1]);
				continue;
			}

			$service->projectForRequest($call[1], ($call[2] ?? '2026-09-30'));
			$results[] = null;
		}

		return ['results' => $results, 'events' => $this->events];

	}//end observe()

	/**
	 * A logger that records every line as an event.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * @param LeaveBalanceProjectionGoldenTest $test The test recording events.
			 */
			public function __construct(private readonly LeaveBalanceProjectionGoldenTest $test) {

			}//end __construct()

			/**
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->events[] = ['log' => $level, 'message' => (string)$message];
			}//end log()
		};

	}//end logger()

	/**
	 * A recording ObjectService over seeded rows. A row id named in
	 * `$failWrite` makes its write throw.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
	 * @param string $failWrite The balance id whose write throws.
	 *
	 * @return object
	 */
	private function store(array $rows, string $failWrite): object {
		$test = $this;
		return new class($rows, $failWrite, $test) {
			/**
			 * The schema of the next read.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
			 * @param string $failWrite The balance id whose write throws.
			 * @param LeaveBalanceProjectionGoldenTest $test The test recording events.
			 */
			public function __construct(private array $rows, private readonly string $failWrite, private readonly LeaveBalanceProjectionGoldenTest $test) {

			}//end __construct()

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options The options.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				if (($this->rows[$this->schema] ?? null) === 'throw') {
					throw new RuntimeException('unreadable ' . $this->schema);
				}

				return ($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The id.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->events[] = ['save' => [$register, $schema, $uuid, $_rbac, $_multitenancy, $object]];
				if ($uuid === $this->failWrite) {
					throw new RuntimeException('write refused');
				}

				foreach (($this->rows[(string)$schema] ?? []) as $i => $row) {
					if ((string)($row['id'] ?? '') === (string)$uuid) {
						$this->rows[(string)$schema][$i] = $object;
					}
				}

				return $object;
			}//end saveObject()
		};

	}//end store()

	/**
	 * A LeaveBalance row.
	 *
	 * @param string $id The id.
	 * @param array<string, mixed> $extra Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function balance(string $id, array $extra = []): array {
		return ($extra + ['id' => $id, 'employeeId' => 'e1', 'leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 160.0, 'bovenwettelijkHours' => 0.0, 'usedHours' => 0.0, 'contractHoursPerWeek' => 40.0]);

	}//end balance()

	/**
	 * A LeaveRequest row.
	 *
	 * @param string $id The id.
	 * @param array<string, mixed> $extra Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(string $id, array $extra = []): array {
		return ($extra + ['id' => $id, 'employeeId' => 'e1', 'leaveType' => 'holiday', 'startDate' => '2026-03-02', 'endDate' => '2026-03-06', 'hours' => 40, 'status' => 'approved']);

	}//end request()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$r1 = self::request('r1');
		$pattern = ['employeeId' => 'e1', 'validFrom' => '2026-01-01', 'hoursMonday' => 8, 'hoursTuesday' => 8, 'hoursWednesday' => 8];

		return [
			'request projected' => ['rows' => ['LeaveBalance' => [self::balance('b1')], 'LeaveRequest' => [$r1]], 'calls' => [['one', $r1]]],
			'request projected twice' => ['rows' => ['LeaveBalance' => [self::balance('b1')], 'LeaveRequest' => [$r1]], 'calls' => [['one', $r1], ['one', $r1]]],
			'blank employee or type' => ['rows' => ['LeaveBalance' => [self::balance('b1')], 'LeaveRequest' => [$r1]], 'calls' => [['one', self::request('r2', ['employeeId' => ' '])], ['one', self::request('r3', ['leaveType' => ''])]]],
			'no balance' => ['rows' => ['LeaveBalance' => [self::balance('b1', ['leaveType' => 'other'])], 'LeaveRequest' => [$r1]], 'calls' => [['one', $r1]]],
			'type draws no balance' => ['rows' => ['LeaveBalance' => [self::balance('b1')], 'LeaveRequest' => [$r1], 'LeaveType' => [['id' => 't1', 'code' => 'holiday', 'drawsFromBalance' => false]]], 'calls' => [['one', $r1], ['all', '2026-09-30']]],
			'unreadable leave types' => ['rows' => ['LeaveBalance' => [self::balance('b1')], 'LeaveRequest' => [$r1], 'LeaveType' => 'throw'], 'calls' => [['one', $r1]]],
			'underivable hours' => [
				'rows' => ['LeaveBalance' => [self::balance('b1', ['contractHoursPerWeek' => null])], 'LeaveRequest' => [self::request('r1', ['hours' => null]), self::request('r2', ['hours' => null, 'startDate' => '2026-04-06', 'endDate' => '2026-04-07'])]],
				'calls' => [['one', self::request('r1', ['hours' => null])]],
			],
			'write fails' => ['rows' => ['LeaveBalance' => [self::balance('b1'), self::balance('b2', ['year' => 2027])], 'LeaveRequest' => [$r1]], 'failWrite' => 'b1', 'calls' => [['one', $r1]]],
			'across new year' => [
				'rows' => [
					'LeaveBalance' => [self::balance('b25', ['year' => 2025, 'expiryDate' => '2026-07-01']), self::balance('b26', ['@self' => ['id' => 'b26'], 'id' => ''])],
					'LeaveRequest' => [self::request('r1', ['startDate' => '2025-12-29', 'endDate' => '2026-01-09', 'hours' => 64]), self::request('r2', ['status' => 'pending'])],
				],
				'calls' => [['one', self::request('r1', ['startDate' => '2025-12-29', 'endDate' => '2026-01-09', 'hours' => 64]), '2026-02-01'], ['one', self::request('r1', ['startDate' => '2025-12-29', 'endDate' => '2026-01-09', 'hours' => 64]), '2026-08-01']],
			],
			'bovenwettelijk carry-over' => [
				'rows' => [
					'LeaveBalance' => [self::balance('b25', ['year' => 2025, 'bovenwettelijkHours' => 40.0, 'expiryDate' => '2026-07-01']), self::balance('b26', ['bovenwettelijkHours' => 24.0])],
					'LeaveRequest' => [self::request('r1', ['startDate' => '2026-02-02', 'endDate' => '2026-02-20', 'hours' => 120])],
					'LeaveType' => [['id' => 'holiday', 'carryOverRule' => 'capped', 'carryOverCapHours' => 16]],
				],
				'calls' => [['all', '2026-12-31']],
			],
			'recompute all' => [
				'rows' => [
					'LeaveBalance' => [
						self::balance('b1'),
						self::balance('b2', ['employeeId' => 'e2', 'usedHours' => 8.0]),
						self::balance('b3', ['leaveType' => 'unpaid']),
						self::balance('b4', ['employeeId' => '']),
						self::balance('b5', ['employeeId' => 'e2', 'leaveType' => 'adv', 'entitledHours' => 20.0]),
					],
					'LeaveRequest' => [$r1, self::request('r2', ['employeeId' => 'e2', 'hours' => 8]), self::request('r3', ['leaveType' => 'unpaid']), self::request('r4', ['employeeId' => 'e2', 'leaveType' => 'adv', 'hours' => 30])],
					'LeaveType' => [['code' => 'unpaid', 'drawsFromBalance' => false]],
				],
				'calls' => [['all', '2026-09-30']],
			],
			'pattern and calendar' => [
				'rows' => ['LeaveBalance' => [self::balance('b1', ['contractHoursPerWeek' => 24.0])], 'LeaveRequest' => [self::request('r1', ['hours' => null]), self::request('r2', ['hours' => null, 'startDate' => 'later'])], 'WorkingPattern' => [$pattern], 'NonWorkingTime' => [['employeeId' => 'e1', 'startDate' => '2026-03-04', 'endDate' => '2026-03-04', 'hoursNotWorked' => 8]]],
				'calendar' => ['2026-03-03'],
				'calls' => [['one', self::request('r1', ['hours' => null])], ['all', '2026-09-30']],
			],
			'unread calendar' => [
				'rows' => ['LeaveBalance' => [self::balance('b1')], 'LeaveRequest' => [self::request('r1', ['hours' => null])], 'WorkingPattern' => [$pattern]],
				'calendar' => null,
				'calls' => [['all', '2026-09-30']],
			],
			'hours from the contract' => ['rows' => ['LeaveBalance' => [self::balance('b1', ['contractHoursPerWeek' => 32.0])], 'LeaveRequest' => [self::request('r1', ['hours' => null])]], 'calls' => [['all', '2026-09-30']]],
			'balance known by its @self id' => [
				'rows' => ['LeaveBalance' => [['@self' => ['id' => 'b9'], 'employeeId' => 'e1', 'leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 80.0, 'usedHours' => 0.0, 'contractHoursPerWeek' => 40.0]], 'LeaveRequest' => [self::request('r1')]],
				'calls' => [['all', '2026-09-30']],
			],
			'calendar with no requests' => ['rows' => ['LeaveBalance' => [self::balance('b1')]], 'calendar' => [], 'calls' => [['all', '2026-09-30']]],
		];

	}//end scenarios()

}//end class
