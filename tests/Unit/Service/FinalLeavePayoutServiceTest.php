<?php

/**
 * Unit tests for FinalLeavePayoutService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drives the service through a fake ObjectService, the one OpenRegister
 * boundary it reads and writes through.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/offboarding-completion/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\FinalLeavePayoutService;
use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The leaver's open hours as pay and the offboarding stamp (REQ-OFC-005).
 *
 * @spec openspec/specs/offboarding-completion/spec.md#requirement-the-open-leave-balance-shall-be-paid-on-the-leavers-final-payslip-req-ofc-005
 */
#[CoversClass(FinalLeavePayoutService::class)]
final class FinalLeavePayoutServiceTest extends TestCase {

	/**
	 * The fake ObjectService.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * A service over the given rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool                                      $failWrites   Whether saveObject throws.
	 *
	 * @return FinalLeavePayoutService
	 */
	private function service(array $rowsBySchema = [], bool $failWrites = false): FinalLeavePayoutService {
		$this->objects = new class($rowsBySchema, $failWrites) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var list<array{schema: string, uuid: string|null, object: array<string, mixed>}>
			 */
			public array $saved = [];

			/**
			 * @param array<string, list<array<string, mixed>>> $rowsBySchema Rows.
			 * @param bool                                      $failWrites   Whether writes throw.
			 */
			public function __construct(public array $rowsBySchema, private bool $failWrites) {
			}//end __construct()

			/**
			 * @param string $register Unused.
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
			 * @param array<string, mixed> $options Unused.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return ($this->rowsBySchema[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object        The row.
			 * @param ObjectSaveOptions    $options       Where it goes.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failWrites === true) {
					throw new RuntimeException('write refused');
				}

				$this->saved[] = ['schema' => (string)$options->target->schema, 'uuid' => $options->uuid, 'object' => $object];
				return $object;
			}//end saveObject()
		};

		$objects   = $this->objects;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new FinalLeavePayoutService($container, $settings, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A leaver on 31 March with 16 open hours at 25 euros an hour.
	 *
	 * @return array<string, mixed>
	 */
	private function leaver(): array {
		return ['id' => 'emp-1', 'employeeNumber' => 'E001', 'endDate' => '2026-03-31', '@self' => ['slug' => 'jan']];
	}//end leaver()

	/**
	 * Scenario: a leaver with 16 open hours gets one line of 16 hours and 400 euros.
	 *
	 * @return void
	 */
	public function testALeaverWithSixteenOpenHoursIsPaidFourHundredEuros(): void {
		$service = $this->service(
			[
				'LeaveBalance' => [
					['id' => 'b1', 'employeeId' => 'emp-1', 'year' => 2026, 'entitledHours' => 40, 'bovenwettelijkHours' => 8, 'usedHours' => 36],
					['id' => 'b2', 'employeeId' => 'E001', 'year' => '2026', 'entitledHours' => 4, 'bovenwettelijkHours' => 0, 'usedHours' => 0],
					['id' => 'b3', 'employeeId' => 'emp-1', 'year' => 2025, 'entitledHours' => 100, 'usedHours' => 0],
				],
			]
		);

		$payout = $service->payoutFor($this->leaver(), ['hourlyWage' => 25], '2026-03', $service->balancesByEmployeeKey());

		self::assertSame(['hours' => 16.0, 'cents' => 40000], $payout);
		self::assertSame(['leavePayout' => ['hours' => 16.0, 'amount' => 400.0]], $service->payslipFields($payout));
	}//end testALeaverWithSixteenOpenHoursIsPaidFourHundredEuros()

	/**
	 * Scenario: a leaver with every hour used gets no line.
	 *
	 * @return void
	 */
	public function testALeaverWithNoOpenHoursGetsNoLine(): void {
		$service = $this->service(
			['LeaveBalance' => [['id' => 'b1', 'employeeId' => 'emp-1', 'year' => 2026, 'entitledHours' => 40, 'bovenwettelijkHours' => 0, 'usedHours' => 48]]]
		);

		$payout = $service->payoutFor($this->leaver(), ['hourlyWage' => 25], '2026-03', $service->balancesByEmployeeKey());

		self::assertNull($payout);
		self::assertSame([], $service->payslipFields($payout));
	}//end testALeaverWithNoOpenHoursGetsNoLine()

	/**
	 * An employee who does not leave in the period, or not at all, is not paid out.
	 *
	 * @return void
	 */
	public function testOnlyThePeriodOfTheEndDatePaysOut(): void {
		$service  = $this->service(['LeaveBalance' => [['id' => 'b1', 'employeeId' => 'emp-1', 'year' => 2026, 'entitledHours' => 40]]]);
		$balances = $service->balancesByEmployeeKey();

		self::assertNull($service->payoutFor($this->leaver(), ['hourlyWage' => 25], '2026-02', $balances));
		self::assertNull($service->payoutFor(['id' => 'emp-1'], ['hourlyWage' => 25], '2026-03', $balances));
	}//end testOnlyThePeriodOfTheEndDatePaysOut()

	/**
	 * Without an hourly wage the rate is the monthly salary over the contract's hours per month.
	 *
	 * @return void
	 */
	public function testTheRateFallsBackToTheMonthlySalary(): void {
		$service  = $this->service(['LeaveBalance' => [['id' => 'b1', 'employeeId' => 'emp-1', 'year' => 2026, 'entitledHours' => 10]]]);
		$employee = array_merge($this->leaver(), ['grossMonthlySalary' => 4333.33]);

		// 433333 cents / (40 h x 52/12) = 2500 cents an hour.
		self::assertSame(['hours' => 10.0, 'cents' => 25000], $service->payoutFor($employee, ['hoursPerWeek' => 40], '2026-03', $service->balancesByEmployeeKey()));
		self::assertNull($service->payoutFor($this->leaver(), ['hoursPerWeek' => 40], '2026-03', $service->balancesByEmployeeKey()));
	}//end testTheRateFallsBackToTheMonthlySalary()

	/**
	 * The leaver's open offboarding case is marked paid; others and already-marked cases are left alone.
	 *
	 * @return void
	 */
	public function testMarkPaidStampsTheLeaversOffboardingCase(): void {
		$service = $this->service(
			[
				'Offboarding' => [
					['id' => 'off-1', 'employeeId' => 'jan', 'status' => 'eindafrekening', 'verlofsaldoUitbetaald' => false, '@self' => ['id' => 'off-1']],
					['id' => 'off-2', 'employeeId' => 'emp-9', 'verlofsaldoUitbetaald' => false],
					['id' => 'off-3', 'employeeId' => 'E001', 'verlofsaldoUitbetaald' => true],
				],
			]
		);

		self::assertSame(1, $service->markPaid([$this->leaver()]));
		self::assertCount(1, $this->objects->saved);
		self::assertSame('Offboarding', $this->objects->saved[0]['schema']);
		self::assertSame('off-1', $this->objects->saved[0]['uuid']);
		self::assertTrue($this->objects->saved[0]['object']['verlofsaldoUitbetaald']);
		self::assertSame('eindafrekening', $this->objects->saved[0]['object']['status']);
		self::assertArrayNotHasKey('@self', $this->objects->saved[0]['object']);
		self::assertSame(0, $service->markPaid([]));
	}//end testMarkPaidStampsTheLeaversOffboardingCase()

	/**
	 * A failed write is not counted and does not throw.
	 *
	 * @return void
	 */
	public function testAFailedStampIsNotCounted(): void {
		$service = $this->service(['Offboarding' => [['id' => 'off-1', 'employeeId' => 'emp-1']]], true);

		self::assertSame(0, $service->markPaid([$this->leaver()]));
	}//end testAFailedStampIsNotCounted()
}//end class
