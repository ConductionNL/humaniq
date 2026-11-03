<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The monthly leave accrual matches the recorded golden: the first balance
 * of a year (statutory hours in full, one bovenwettelijk slice, expiry
 * date, account link), the monthly slice onto an existing balance (raised
 * but never lowered entitlement and contract hours), the no-op for a month
 * already accrued, every skip reason and a write that fails. Each case
 * records the summary and every write with its register, schema, uuid and
 * RBAC and multitenancy flags. The job writes as the system (no acting
 * user): every write must carry `_rbac: false`, and the account link comes
 * from the employee row only.
 *
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use OCA\Humaniq\BackgroundJob\LeaveAccrualJob;
use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Golden test for {@see LeaveAccrualJob::runAccrual()}.
 *
 * @covers \OCA\Humaniq\BackgroundJob\LeaveAccrualJob
 */
class LeaveAccrualGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/leave-accrual.json';

	/**
	 * Every case produces the recorded summary and writes.
	 *
	 * @return void
	 */
	public function testRunAccrualMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		if (getenv('HUMANIQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(
				json_encode($expected, JSON_PRESERVE_ZERO_FRACTION),
				json_encode($actual[$name], JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testRunAccrualMatchesTheGolden()

	/**
	 * The cases: one world of employees, contracts and balances, run on
	 * several dates and bovenwettelijk settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$employees = [
			['id' => 'e-new', 'firstName' => 'Nieuw', 'startDate' => '2020-01-01', 'nextcloudUserId' => ' new.user '],
			['id' => 'e-part', 'firstName' => 'Deel', 'lastName' => 'Tijd', 'startDate' => '2020-01-01', 'nextcloudUserId' => ''],
			['@self' => ['id' => 'e-self', 'slug' => 'e-slug'], 'employeeNumber' => 'N-7', 'startDate' => '2020-01-01', 'nextcloudUserId' => 'self.user'],
			['id' => 'e-raise', 'firstName' => 'Meer', 'startDate' => '2020-01-01', 'nextcloudUserId' => 'raise.user'],
			['id' => 'e-lower', 'firstName' => 'Minder', 'startDate' => '2020-01-01'],
			['id' => 'e-done', 'firstName' => 'Klaar', 'startDate' => '2020-01-01', 'nextcloudUserId' => 'done.user'],
			['id' => 'e-fail', 'firstName' => 'Faal', 'startDate' => '2020-01-01', 'nextcloudUserId' => 'fail.user'],
			['id' => 'e-failx', 'firstName' => 'Faal', 'lastName' => 'Bestaand', 'startDate' => '2020-01-01'],
			['id' => 'e-nocontract', 'firstName' => 'Geen', 'startDate' => '2020-01-01'],
			['id' => 'e-zero', 'employeeNumber' => 'Z-1', 'startDate' => '2020-01-01'],
			['id' => 'e-text', 'startDate' => '2020-01-01'],
			['firstName' => 'Zonder', 'lastName' => 'Id', 'startDate' => '2020-01-01'],
			['id' => 'e-left', 'firstName' => 'Weg', 'startDate' => '2020-01-01', 'endDate' => '2021-01-01'],
			['id' => 'e-future', 'firstName' => 'Later', 'startDate' => '2099-01-01'],
		];
		$contracts = [
			['employeeId' => 'e-new', 'startDate' => '2020-01-01', 'hoursPerWeek' => 40],
			['employeeId' => 'e-part', 'startDate' => '2020-01-01', 'hoursPerWeek' => '32.5'],
			['employeeId' => 'N-7', 'startDate' => '2020-01-01', 'hoursPerWeek' => 24],
			['employeeId' => 'e-raise', 'startDate' => '2020-01-01', 'endDate' => '2020-12-31', 'hoursPerWeek' => 99],
			['employeeId' => 'e-raise', 'startDate' => '2021-01-01', 'hoursPerWeek' => 36],
			['employeeId' => 'e-lower', 'startDate' => '2020-01-01', 'hoursPerWeek' => 16],
			['employeeId' => 'e-done', 'startDate' => '2020-01-01', 'hoursPerWeek' => 40],
			['employeeId' => 'e-fail', 'startDate' => '2020-01-01', 'hoursPerWeek' => 40],
			['employeeId' => 'e-failx', 'startDate' => '2020-01-01', 'hoursPerWeek' => 40],
			['employeeId' => 'e-nocontract', 'startDate' => '2099-01-01', 'hoursPerWeek' => 40],
			['employeeId' => 'Z-1', 'startDate' => '2020-01-01', 'hoursPerWeek' => 0],
			['employeeId' => 'e-text', 'startDate' => '2020-01-01', 'hoursPerWeek' => 'veertig'],
			['employeeId' => 'e-left', 'startDate' => '2020-01-01', 'hoursPerWeek' => 40],
		];
		$balances = static fn (int $year, string $donePeriod): array => [
			['id' => 'lb-raise', 'employeeId' => 'e-raise', 'year' => $year, 'leaveType' => 'holiday', 'entitledHours' => 128.0, 'bovenwettelijkHours' => 3.3, 'usedHours' => 12.5, 'contractHoursPerWeek' => 32.0, 'lastAccruedPeriod' => '2001-01', 'userId' => 'stale.user', '@self' => ['id' => 'lb-raise', 'owner' => 'x']],
			['@self' => ['id' => 'lb-lower'], 'employeeId' => 'e-lower', 'year' => $year, 'leaveType' => 'holiday', 'entitledHours' => 160, 'bovenwettelijkHours' => '1.5', 'contractHoursPerWeek' => 40, 'lastAccruedPeriod' => ''],
			['id' => 'lb-done', 'employeeId' => 'e-done', 'year' => $year, 'leaveType' => 'holiday', 'entitledHours' => 160.0, 'lastAccruedPeriod' => $donePeriod],
			['id' => 'lb-failx', 'employeeId' => 'e-failx', 'year' => $year, 'leaveType' => 'holiday', 'entitledHours' => 160.0, 'lastAccruedPeriod' => '2001-01'],
			['id' => 'lb-oldyear', 'employeeId' => 'e-new', 'year' => $year - 1, 'leaveType' => 'holiday', 'entitledHours' => 160.0],
			['id' => 'lb-special', 'employeeId' => 'e-part', 'year' => $year, 'leaveType' => 'special', 'entitledHours' => 8.0],
		];

		$cases = [];
		foreach (['2026-07-15' => '2026-07', '2026-01-01' => '2026-01', '2026-12-31' => '2026-12', '2028-02-29' => '2028-02'] as $now => $period) {
			foreach ([0.0, 25.0, 13.33, 40.2] as $annual) {
				$year = (int)substr($now, 0, 4);
				$cases[$now . ' bovenwettelijk ' . $annual] = [
					'now' => $now,
					'annual' => $annual,
					'rows' => ['Employee' => $employees, 'EmploymentContract' => $contracts, 'LeaveBalance' => $balances($year, $period)],
					'saveThrows' => ($annual === 25.0 ? 'e-fail' : ($annual === 13.33 ? 'e-failx' : '')),
				];
			}
		}

		$cases['entity answers'] = ['now' => '2026-07-15', 'annual' => 25.0, 'rows' => ['Employee' => $employees, 'EmploymentContract' => $contracts, 'LeaveBalance' => $balances(2026, '2026-07')], 'entity' => true];
		$cases['disabled'] = ['now' => '2026-07-15', 'annual' => 25.0, 'rows' => ['Employee' => $employees, 'EmploymentContract' => $contracts], 'enabled' => false];

		return $cases;
	}//end cases()

	/**
	 * Run one case and record its summary and writes.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$store = new class($case['rows'], (string)($case['saveThrows'] ?? ''), (bool)($case['entity'] ?? false)) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @var list<array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * @param array<string, list<array<string, mixed>>> $rows Seed rows keyed by schema.
			 * @param string $saveThrows The employeeId whose balance write throws ('' for none).
			 * @param bool $entity Whether a LeaveBalance write answers an entity.
			 */
			public function __construct(private array $rows, private readonly string $saveThrows, private readonly bool $entity) {
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
			 * @param array<string, mixed> $options Query options.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rows[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): mixed {
				$recorded = $object;
				$this->writes[] = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'object' => $recorded];
				if ($this->saveThrows !== '' && ($object['employeeId'] ?? null) === $this->saveThrows) {
					throw new \RuntimeException('the register refused employee ' . $this->saveThrows);
				}

				$id = ($uuid ?? ($schema . '-new-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);
				$this->rows[(string)$schema][] = $saved;
				if ($this->entity === true) {
					unset($saved['id']);
					$saved['@self'] = ['id' => $id];
					return new class($saved) implements \JsonSerializable {

						/**
						 * @param array<string, mixed> $row The row.
						 */
						public function __construct(private readonly array $row) {
						}//end __construct()

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->row;
						}//end jsonSerialize()

					};
				}

				return $saved;
			}//end saveObject()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('isLeaveAccrualEnabled')->willReturn((bool)($case['enabled'] ?? true));
		$settings->method('getLeaveBovenwettelijkAnnualHours')->willReturn((float)$case['annual']);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTimeImmutable($case['now'] . ' 12:00:00'))->getTimestamp());

		$job = new LeaveAccrualJob($time, $container, $settings, new NullLogger(), $this->createMock(LeaveBalanceProjectionService::class));

		return ['summary' => $job->runAccrual(), 'writes' => $store->writes];
	}//end runCase()
}//end class
