<?php

/**
 * Unit tests for LeaveAccrualJob's skip reporting.
 *
 * Pins the per-employee skip reasons the main suite leaves open: an
 * employee row without an identity, and a LeaveBalance write that fails on
 * the create path and on the monthly accrual path, each reported as
 * `save-failed` with a warning naming the employee, while the other
 * employees of the run carry on.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\BackgroundJob
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
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use OCA\Humaniq\BackgroundJob\LeaveAccrualJob;
use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * LeaveAccrualJob skip reasons.
 */
class LeaveAccrualJobSkipReasonsTest extends TestCase {

	/**
	 * Warnings the job logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A register double that serves rows per schema and refuses every write.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object
	 */
	private function refusingObjectService(array $rowsBySchema): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {
			}//end __construct()

			/**
			 * @param string $register Register slug (unused).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * Accepts whatever save signature the job uses, and refuses it.
			 *
			 * @param mixed ...$arguments The save arguments (unused).
			 *
			 * @return array<string, mixed>
			 *
			 * @throws \RuntimeException Always: the register refuses the write.
			 */
			public function saveObject(mixed ...$arguments): array {
				throw new \RuntimeException('opslag geweigerd');
			}//end saveObject()
		};

	}//end refusingObjectService()

	/**
	 * Build the job over the refusing register, on 2026-07-15.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return LeaveAccrualJob
	 */
	private function job(array $rowsBySchema): LeaveAccrualJob {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->refusingObjectService($rowsBySchema));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('isLeaveAccrualEnabled')->willReturn(true);
		$settings->method('getLeaveBovenwettelijkAnnualHours')->willReturn(24.0);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTimeImmutable('2026-07-15'))->getTimestamp());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new LeaveAccrualJob($time, $container, $settings, $logger, $this->createMock(LeaveBalanceProjectionService::class));
	}//end job()

	/**
	 * An employee row without an id is reported, the others still run.
	 *
	 * @return void
	 */
	public function testEmployeeWithoutIdentityIsSkippedWithReason(): void {
		$summary = $this->job(
			[
				'Employee' => [
					['firstName' => 'Anna', 'lastName' => 'Smit', 'startDate' => '2020-01-01', 'endDate' => null],
					['id' => 'emp-2', 'employeeNumber' => 'E-2', 'startDate' => '2020-01-01', 'endDate' => null],
				],
				'EmploymentContract' => [],
				'LeaveBalance' => [],
			]
		)->runAccrual();

		self::assertSame(
			[
				['employee' => 'Anna Smit', 'reason' => 'no-employee-identity'],
				['employee' => 'E-2', 'reason' => 'no-covering-contract'],
			],
			$summary['skipped']
		);
		self::assertSame([], $summary['provisioned']);
		self::assertSame([], $this->warnings);

	}//end testEmployeeWithoutIdentityIsSkippedWithReason()

	/**
	 * A refused write is reported as `save-failed` on both the create and the
	 * accrual path, with a warning naming the employee; a balance already
	 * accrued this month stays a no-op.
	 *
	 * @return void
	 */
	public function testRefusedWritesAreSkippedAsSaveFailed(): void {
		$summary = $this->job(
			[
				'Employee' => [
					['id' => 'emp-1', 'firstName' => 'Anna', 'lastName' => 'Smit', 'startDate' => '2020-01-01', 'endDate' => null],
					['id' => 'emp-2', 'employeeNumber' => 'E-2', 'startDate' => '2020-01-01', 'endDate' => null],
					['id' => 'emp-3', 'startDate' => '2020-01-01', 'endDate' => null],
				],
				'EmploymentContract' => [
					['employeeId' => 'emp-1', 'startDate' => '2020-01-01', 'endDate' => null, 'hoursPerWeek' => 40],
					['employeeId' => 'emp-2', 'startDate' => '2020-01-01', 'endDate' => null, 'hoursPerWeek' => 32],
					['employeeId' => 'emp-3', 'startDate' => '2020-01-01', 'endDate' => null, 'hoursPerWeek' => 24],
				],
				'LeaveBalance' => [
					['id' => 'lb-2', 'employeeId' => 'emp-2', 'year' => 2026, 'leaveType' => 'holiday', 'lastAccruedPeriod' => '2026-06'],
					['id' => 'lb-3', 'employeeId' => 'emp-3', 'year' => 2026, 'leaveType' => 'holiday', 'lastAccruedPeriod' => '2026-07'],
				],
			]
		)->runAccrual();

		self::assertSame(
			[
				'period' => '2026-07',
				'year' => 2026,
				'enabled' => true,
				'provisioned' => [],
				'accrued' => [],
				'skipped' => [
					['employee' => 'Anna Smit', 'reason' => 'save-failed'],
					['employee' => 'E-2', 'reason' => 'save-failed'],
				],
				'noop' => 1,
			],
			$summary
		);
		self::assertSame(
			[
				'LeaveAccrualJob: kon LeaveBalance niet aanmaken voor Anna Smit: opslag geweigerd',
				'LeaveAccrualJob: kon LeaveBalance niet bijwerken voor E-2: opslag geweigerd',
			],
			$this->warnings
		);

	}//end testRefusedWritesAreSkippedAsSaveFailed()
}//end class
