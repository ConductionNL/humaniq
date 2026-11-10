<?php

/**
 * Unit tests for PayrollRunService's sick-pay integration.
 *
 * Pins the sick-pay-calc service contract (design.md D4): an open (gemeld)
 * SickLeaveCase covering the run period substitutes the doorbetaald loon for
 * the full salary as PayrollCalculator's gross input, the generated Payslip
 * is stamped with the sick-pay fields, and a second run without
 * `--recalculate` is an idempotent no-op (the existing probe-before-create
 * path, re-exercised through the sick branch). Drives the service through
 * the same fake ObjectService double idiom as `PayrollRunServiceTest`.
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\WageTaxElection;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\SickPayCalculator;
use OCA\Humaniq\Payroll\SickPayCircumstances;
use OCA\Humaniq\Payroll\SickPayHours;
use OCA\Humaniq\Payroll\SickPayInput;
use OCA\Humaniq\Payroll\SickPayTiming;
use OCA\Humaniq\Payroll\SickPayWage;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\PayrollRetentionGuardService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollRunService's sick-pay integration.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
 */
class PayrollRunServiceSickPayTest extends TestCase {

	/**
	 * Build a fake ObjectService double — identical idiom to
	 * `PayrollRunServiceTest::fakeObjectService()`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<int, string>
			 */
			public array $deleted = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
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
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param ObjectSaveOptions $options The register, schema, uuid and the rest of the save settings.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 */
			public function saveObject(
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$targetSchema = ($schema ?? $this->schema);

				$id = ($uuid ?? ('generated-' . $targetSchema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);

				$this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$replaced = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$i] = $saved;
						$replaced = true;
						break;
					}
				}

				if ($replaced === false) {
					$rows[] = $saved;
				}

				$this->rowsBySchema[$targetSchema] = $rows;

				return $saved;
			}//end saveObject()

			/**
			 * @param string $uuid The object id to delete.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return bool
			 */
			public function deleteObject(
				string $uuid,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): bool {
				$this->deleted[] = $uuid;

				$targetSchema = ($schema ?? $this->schema);
				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $uuid) {
						unset($rows[$i]);
						break;
					}
				}

				$this->rowsBySchema[$targetSchema] = array_values($rows);

				return true;
			}//end deleteObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired PayrollRunService plus its fake ObjectService
	 * double (for assertions on what was saved/deleted).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: PayrollRunService, 1: object}
	 */
	private function service(array $rowsBySchema = []): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getPayrollAofTariff')->willReturn('laag');
		$settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);

		$retentionGuard = $this->createMock(PayrollRetentionGuardService::class);

		return [new PayrollRunService($container, $settings, new PayrollCalculator(), new SickPayCalculator(), $retentionGuard, $logger), $fake];
	}//end service()

	/**
	 * The anchor-case Employee fixture (design.md D2: €3.800, wit, korting,
	 * below AOW), overridable per test.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'emp-1',
				'employeeNumber' => 'EMP-NL-0001',
				'firstName' => 'Sanne',
				'lastName' => 'de Vries',
				'dateOfBirth' => '1990-04-12',
				'startDate' => '2022-01-01',
				'endDate' => null,
				'grossMonthlySalary' => 3800.00,
				'taxTableColor' => 'wit',
				'loonheffingskortingToegepast' => true,
				'bsn' => '123456782',
				'identityDocumentVerified' => true,
				'nextcloudUserId' => 'sanne',
			],
			$overrides
		);

	}//end employee()

	/**
	 * The covering-contract fixture for the anchor employee.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'ct-1',
				'employeeId' => 'emp-1',
				'type' => 'permanent',
				'writtenContract' => true,
				'startDate' => '2022-01-01',
				'endDate' => null,
				'hoursPerWeek' => 36.0,
				'awfTariff' => 'low',
			],
			$overrides
		);

	}//end contract()

	/**
	 * An open (gemeld) SickLeaveCase fixture covering the anchor employee,
	 * inside the run period, year 1, no wachtdag, 70%.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function sickCase(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'case-1',
				'employeeId' => 'emp-1',
				'firstSickDay' => '2026-02-10',
				'status' => 'gemeld',
				'wachtdag' => false,
				'loondoorbetalingPercentage' => 70,
			],
			$overrides
		);

	}//end sickCase()

	/**
	 * @return void
	 */
	public function testOpenCaseEmployeePayslipReflectsDoorbetaaldLoonNotFullSalary(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'SickLeaveCase' => [$this->sickCase()],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('calculated', $result['status']);
		$this->assertCount(1, $result['computed']);
		$this->assertSame([], $result['skipped']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		// The design.md D2 anchor sick-pay figures — doorbetaald loon
		// (€2.660,00), not the full €3.800,00 salary.
		$this->assertSame(2660.00, $payslip['grossPay'], 'grossPay must be the doorbetaald loon, not the full salary.');
		$this->assertSame('case-1', $payslip['sickLeaveCaseId']);
		$this->assertSame(2660.00, $payslip['doorbetaaldLoon']);
		$this->assertSame(0.00, $payslip['wachtdagDeduction']);
		$this->assertSame(3800.00, $payslip['sickPayReferenceWage']);
		$this->assertSame(70.0, $payslip['sickPayPercentage']);
		$this->assertSame(2294.40, $payslip['sickPayMinimumWageFloor']);
		$this->assertTrue($payslip['sickPayYearOne']);

		// loonheffing/net are computed on the doorbetaald loon, not the full
		// salary (REQ-SICK-005 scenario) — cross-checked against a direct
		// PayrollCalculator call on the same gross.
		$tables = TaxTables::load('nl-2026');
		$expectedOnDoorbetaald = (new PayrollCalculator())->calculate(
			new CalculationInput(
				wage: new CalculationWage(
					grossMonthlySalaryCents: 266000,
					dateOfBirth: '1990-04-12',
					period: '2026-02'
				),
				levies: new CalculationLevies(
					wageTax: new WageTaxElection(
						taxTableColor: 'wit',
						loonheffingskortingToegepast: true
					),
					insurance: new InsuranceTariffs(
						awfTariff: 'low',
						aofTariff: 'laag',
						whkPercentage: 1.52
					)
				)
			),
			$tables
		);
		$this->assertSame(round(($expectedOnDoorbetaald->nettoPayCents / 100), 2), $payslip['nettoPay']);
		$this->assertNotSame(3081.17, $payslip['nettoPay'], 'The anchor full-salary nettoPay must NOT be reused for a sick employee.');

	}//end testOpenCaseEmployeePayslipReflectsDoorbetaaldLoonNotFullSalary()

	/**
	 * Cases where the year-one flag, the first sick day in the period, the
	 * wachtdag and the contract hours each differ from their neighbour, so a
	 * swapped argument shows: name => [contract hours, first sick day,
	 * wachtdag, year one, first sick day in the run period].
	 *
	 * @return array<string, array{0: float, 1: string, 2: bool, 3: bool, 4: bool}>
	 */
	public static function sickPayCircumstances(): array {
		return [
			'24 hours, sick since January, wachtdag' => [24.0, '2026-01-05', true, true, false],
			'24 hours, sick in the period, no wachtdag' => [24.0, '2026-02-10', false, true, true],
			'24 hours, sick in the period, wachtdag' => [24.0, '2026-02-10', true, true, true],
			'24 hours, past week 52, wachtdag' => [24.0, '2024-12-02', true, false, false],
			'40 hours, past week 52, no wachtdag' => [40.0, '2024-12-02', false, false, false],
		];

	}//end sickPayCircumstances()

	/**
	 * The stamped sick-pay figures follow the case and the contract: year one
	 * from the first sick day, the wachtdag only when the case has one and
	 * starts in the period, and the WML floor scaled by the contract hours.
	 * Each expected payslip is computed straight from SickPayCalculator with
	 * the circumstances stated by hand.
	 *
	 * @param float $hours The contract hours per week.
	 * @param string $firstSickDay The case's first sick day.
	 * @param bool $wachtdag Whether the case has a wachtdag.
	 * @param bool $yearOne Whether 2026-02 falls in the case's first 52 weeks.
	 * @param bool $firstSickDayInPeriod Whether the first sick day falls in 2026-02.
	 *
	 * @return void
	 *
	 * @dataProvider sickPayCircumstances
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
	 */
	public function testStampedSickPayFollowsTheCaseAndTheContract(float $hours, string $firstSickDay, bool $wachtdag, bool $yearOne, bool $firstSickDayInPeriod): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract(['hoursPerWeek' => $hours])],
				'SickLeaveCase' => [$this->sickCase(['firstSickDay' => $firstSickDay, 'wachtdag' => $wachtdag])],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$this->assertSame('calculated', $service->runFor('2026-02')['status']);
		$payslip = $this->savedFor($fake, 'Payslip')[0];

		$expected = (new SickPayCalculator())->compute(
			new SickPayInput(
				wage: new SickPayWage(referenceWageCents: 380000, aangepastLoonCents: 0, loondoorbetalingPercentage: 70.0),
				circumstances: new SickPayCircumstances(
					timing: new SickPayTiming(yearOne: $yearOne, wachtdag: $wachtdag, firstSickDayInPeriod: $firstSickDayInPeriod),
					hours: new SickPayHours(contractHoursPerWeek: $hours, fulltimeHoursPerWeek: 36.0)
				)
			),
			TaxTables::load('nl-2026')
		);

		$this->assertSame($yearOne, $payslip['sickPayYearOne']);
		$this->assertSame(round(($expected->doorbetaaldLoonCents / 100), 2), $payslip['doorbetaaldLoon']);
		$this->assertSame(round(($expected->wachtdagDeductionCents / 100), 2), $payslip['wachtdagDeduction']);
		$this->assertSame(round(($expected->minimumWageFloorCents / 100), 2), $payslip['sickPayMinimumWageFloor']);
		$this->assertSame(round(($expected->payableGrossCents / 100), 2), $payslip['grossPay']);

	}//end testStampedSickPayFollowsTheCaseAndTheContract()

	/**
	 * A normal employee (no open SickLeaveCase) is completely unaffected —
	 * the full-salary path stays byte-identical, and every sick-pay field is
	 * null (REQ-SICK-001 scenario: "The reference wage is untouched when no
	 * sick case applies").
	 *
	 * @return void
	 */
	public function testNoOpenCaseLeavesTheFullSalaryPathUnchanged(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'SickLeaveCase' => [],
				'PayrollRun' => [],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips);
		$payslip = $payslips[0];

		$this->assertSame(3800.00, $payslip['grossPay']);
		$this->assertSame(3081.17, $payslip['nettoPay']);
		$this->assertNull($payslip['sickLeaveCaseId']);
		$this->assertNull($payslip['doorbetaaldLoon']);
		$this->assertNull($payslip['wachtdagDeduction']);
		$this->assertNull($payslip['sickPayReferenceWage']);
		$this->assertNull($payslip['sickPayPercentage']);
		$this->assertNull($payslip['sickPayMinimumWageFloor']);
		$this->assertNull($payslip['sickPayYearOne']);

	}//end testNoOpenCaseLeavesTheFullSalaryPathUnchanged()

	/**
	 * A second run for the same (period, administrationId) without
	 * `--recalculate` is an idempotent no-op, even with an open sick case in
	 * play (REQ-SICK-007).
	 *
	 * @return void
	 */
	public function testSecondSickPayRunWithoutRecalculateIsAnIdempotentNoOp(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'SickLeaveCase' => [$this->sickCase()],
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'draft'],
				],
				'Payslip' => [],
			]
		);

		$result = $service->runFor('2026-02');

		$this->assertSame('exists', $result['status']);
		$this->assertSame('run-1', $result['runId']);
		$this->assertSame([], $fake->saved, 'An idempotent no-op must write nothing.');
		$this->assertSame([], $fake->deleted);

	}//end testSecondSickPayRunWithoutRecalculateIsAnIdempotentNoOp()

	/**
	 * Recalculating an already-computed sick-pay run in place changes
	 * nothing else and does not duplicate the payslip (REQ-SICK-007
	 * idempotency).
	 *
	 * @return void
	 */
	public function testRecalculatingASickPayRunUpsertsInPlaceWithoutDuplicating(): void {
		[$service, $fake] = $this->service(
			[
				'Employee' => [$this->employee()],
				'EmploymentContract' => [$this->contract()],
				'SickLeaveCase' => [$this->sickCase()],
				'PayrollRun' => [
					['id' => 'run-1', 'period' => '2026-02', 'administrationId' => 'ADM-001', 'jurisdiction' => 'NL', 'status' => 'draft'],
				],
				'Payslip' => [
					['id' => 'ps-emp1', 'payrollRunId' => 'run-1', 'employeeId' => 'emp-1', 'period' => '2026-02', 'grossPay' => 1.00, 'nettoPay' => 1.00],
				],
			]
		);

		$result = $service->runFor('2026-02', 'ADM-001', true);

		$this->assertSame('calculated', $result['status']);

		$payslips = $this->savedFor($fake, 'Payslip');
		$this->assertCount(1, $payslips, 'The sick-case employee\'s payslip must be updated in place, never duplicated.');
		$this->assertSame('ps-emp1', $payslips[0]['id']);
		$this->assertSame(2660.00, $payslips[0]['grossPay']);
		$this->assertSame('case-1', $payslips[0]['sickLeaveCaseId']);

	}//end testRecalculatingASickPayRunUpsertsInPlaceWithoutDuplicating()

	/**
	 * A sick case without a loondoorbetaling percentage pays 70%.
	 *
	 * @return void
	 */
	public function testACaseWithoutAPercentagePaysSeventyPercent(): void {
		$case = $this->sickCase();
		unset($case['loondoorbetalingPercentage']);
		[$service, $fake] = $this->service(['Employee' => [$this->employee()], 'EmploymentContract' => [$this->contract()], 'SickLeaveCase' => [$case], 'PayrollRun' => [], 'Payslip' => []]);

		$this->assertSame('calculated', $service->runFor('2026-02')['status']);
		$payslip = $this->savedFor($fake, 'Payslip')[0];

		$this->assertSame(70.0, $payslip['sickPayPercentage']);
		$this->assertSame(2660.00, $payslip['doorbetaaldLoon']);

	}//end testACaseWithoutAPercentagePaysSeventyPercent()

	/**
	 * An aangepast loon with a half cent is rounded to the nearest cent,
	 * not floored: 3000.125 is 300013 cents. At 50% continuation that cent
	 * shows in the doorbetaald loon.
	 *
	 * @return void
	 */
	public function testAnAangepastLoonWithAHalfCentIsRoundedToTheNearestCent(): void {
		[$service, $fake] = $this->service(['Employee' => [$this->employee()], 'EmploymentContract' => [$this->contract()], 'SickLeaveCase' => [$this->sickCase(['aangepastLoon' => 3000.125, 'loondoorbetalingPercentage' => 50])], 'PayrollRun' => [], 'Payslip' => []]);

		$this->assertSame('calculated', $service->runFor('2026-02')['status']);
		$payslip = $this->savedFor($fake, 'Payslip')[0];

		$expected = static function (int $aangepastLoonCents): \OCA\Humaniq\Payroll\SickPayResult {
			return (new SickPayCalculator())->compute(
				new \OCA\Humaniq\Payroll\SickPayInput(
					wage: new \OCA\Humaniq\Payroll\SickPayWage(referenceWageCents: 380000, aangepastLoonCents: $aangepastLoonCents, loondoorbetalingPercentage: 50.0),
					circumstances: new \OCA\Humaniq\Payroll\SickPayCircumstances(
						timing: new \OCA\Humaniq\Payroll\SickPayTiming(yearOne: true, wachtdag: false, firstSickDayInPeriod: true),
						hours: new \OCA\Humaniq\Payroll\SickPayHours(contractHoursPerWeek: 36.0, fulltimeHoursPerWeek: 36.0)
					)
				),
				TaxTables::load('nl-2026')
			);
		};
		$rounded = $expected(300013);
		$this->assertNotSame($rounded->payableGrossCents, $expected(300012)->payableGrossCents, 'One cent of aangepast loon shows in the payable gross.');
		$this->assertSame(round(($rounded->payableGrossCents / 100), 2), $payslip['grossPay']);
		$this->assertSame(round(($rounded->doorbetaaldLoonCents / 100), 2), $payslip['doorbetaaldLoon']);

	}//end testAnAangepastLoonWithAHalfCentIsRoundedToTheNearestCent()

	/**
	 * Objects saved to a given schema, in save order.
	 *
	 * @param object $fake The fake ObjectService.
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(object $fake, string $schema): array {
		$out = [];
		foreach ($fake->saved as $entry) {
			if ($entry['schema'] === $schema) {
				$out[] = $entry['object'];
			}
		}

		return $out;
	}//end savedFor()

}//end class
