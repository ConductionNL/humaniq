<?php

/**
 * PensionMessageServiceTest
 *
 * The lines and findings of a pension filing: one line per employee of the
 * approved run with the fund's scheme, and a finding naming an employee
 * whose contract has no hours per week, a fund without a scheme and a run
 * that is not approved.
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
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PensionMessageService;
use OCA\Humaniq\Service\RecordStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * PensionMessageService over a register held in memory.
 *
 * @covers \OCA\Humaniq\Service\PensionMessageService
 * @uses   \OCA\Humaniq\Payroll\Pension\PensionBase
 *
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */
class PensionMessageServiceTest extends TestCase {

	/**
	 * The June filing of fund pfab on run-06.
	 *
	 * @var array<string, mixed>
	 */
	private const FILING = ['id' => 'f-1', 'period' => '2026-06', 'fund' => 'pfab', 'payrollRunId' => 'run-06', 'administrationId' => 'ADM-001'];

	/**
	 * The service over a register of the given objects, by schema.
	 *
	 * @param array<string, list<array<string, mixed>>> $objects The objects per schema.
	 *
	 * @return PensionMessageService
	 */
	private function service(array $objects): PensionMessageService {
		$gateway = $this->createMock(RecordStoreInterface::class);
		$gateway->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters) use ($objects): array {
				return array_values(array_filter(
					($objects[$schema] ?? []),
					static function (array $object) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if (($object[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}
		);
		$gateway->method('findObjectData')->willReturnCallback(
			static function (string $uuid, string $schema) use ($objects): ?array {
				foreach (($objects[$schema] ?? []) as $object) {
					if (($object['id'] ?? null) === $uuid) {
						return $object;
					}
				}

				return null;
			}
		);

		return new PensionMessageService(gateway: $gateway, fullTimeHoursWeek: 40.0);
	}

	/**
	 * A register with an approved June run, two payslips, two contracts and
	 * the pfab scheme; the second contract's hours are the given value.
	 *
	 * @param mixed $secondHours The hours per week of the second contract.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function register(mixed $secondHours=20): array {
		return [
			'PayrollRun' => [['id' => 'run-06', 'status' => 'approved', 'period' => '2026-06']],
			'PensionScheme' => [
				['administrationId' => 'ADM-001', 'fund' => 'pfab', 'messageFormat' => 'upa', 'franchise' => 12000, 'maxPensionableSalary' => 120000, 'employerPercentage' => 10, 'employeePercentage' => 5, 'validFrom' => '2025-01-01', 'validUntil' => '2025-12-31'],
				['administrationId' => 'ADM-001', 'fund' => 'pfab', 'messageFormat' => 'upa', 'franchise' => 24000, 'maxPensionableSalary' => 120000, 'employerPercentage' => 10, 'employeePercentage' => 5, 'validFrom' => '2026-01-01', 'validUntil' => ''],
				['administrationId' => 'ADM-002', 'fund' => 'pfab', 'messageFormat' => 'apg', 'franchise' => 0, 'maxPensionableSalary' => 1, 'validFrom' => '2026-01-01'],
			],
			'Payslip' => [
				['payrollRunId' => 'run-06', 'employeeId' => 'e-1', 'grossPay' => 5000.0],
				['payrollRunId' => 'run-06', 'employeeId' => 'e-2', 'grossPay' => 2000.0],
				['payrollRunId' => 'run-05', 'employeeId' => 'e-3', 'grossPay' => 9999.0],
			],
			'Employee' => [
				['id' => 'e-1', 'firstName' => 'Anna', 'lastName' => 'de Vries', 'employeeNumber' => 'N-1'],
				['id' => 'e-2', 'firstName' => 'Bram', 'lastName' => 'Smit', 'employeeNumber' => 'N-2'],
			],
			'EmploymentContract' => [
				['id' => 'c-old', 'employeeId' => 'e-1', 'startDate' => '2020-01-01', 'endDate' => '2025-12-31', 'hoursPerWeek' => 8],
				['id' => 'c-1', 'employeeId' => 'e-1', 'startDate' => '2026-01-01', 'endDate' => '', 'hoursPerWeek' => 40],
				['id' => 'c-2', 'employeeId' => 'e-2', 'startDate' => '2026-03-01', 'endDate' => '2026-12-31', 'hoursPerWeek' => $secondHours],
			],
		];
	}

	/**
	 * Every employee of the approved run gets a line under the scheme of the period.
	 *
	 * @return void
	 */
	public function testEveryEmployeeOfTheApprovedRunGetsALineUnderTheSchemeOfThePeriod(): void {
		$outcome = $this->service($this->register())->lines(self::FILING);

		$this->assertSame([], $outcome['findings']);
		$this->assertSame('upa', $outcome['messageFormat']);
		$this->assertSame('2026-01-01', $outcome['scheme']['validFrom']);
		$this->assertSame(
			[
				[
					'employeeId' => 'e-1',
					'employeeNumber' => 'N-1',
					'startDate' => '2026-01-01',
					'endDate' => '',
					'partTimeFactor' => 1.0,
					'pensionableWage' => 5000.0,
					'pensionBase' => 3000.0,
					'employerContribution' => 300.0,
					'employeeContribution' => 150.0,
				],
				[
					'employeeId' => 'e-2',
					'employeeNumber' => 'N-2',
					'startDate' => '2026-03-01',
					'endDate' => '2026-12-31',
					'partTimeFactor' => 0.5,
					'pensionableWage' => 2000.0,
					'pensionBase' => 1000.0,
					'employerContribution' => 100.0,
					'employeeContribution' => 50.0,
				],
			],
			$outcome['lines']
		);
	}

	/**
	 * A contract without hours per week names the employee and the missing part time factor.
	 *
	 * @return void
	 */
	public function testAContractWithoutHoursPerWeekNamesTheEmployeeAndTheMissingPartTimeFactor(): void {
		$outcome = $this->service($this->register(secondHours: null))->lines(self::FILING);

		$this->assertCount(1, $outcome['lines']);
		$this->assertSame(
			[[
				'kind' => 'no-part-time-factor',
				'severity' => 'blocking',
				'employeeId' => 'e-2',
				'problem' => 'Bram Smit heeft een arbeidsovereenkomst zonder uren per week, dus de deeltijdfactor ontbreekt.',
			]],
			$outcome['findings']
		);
	}

	/**
	 * An employee without a contract in the period is named.
	 *
	 * @return void
	 */
	public function testAnEmployeeWithoutAContractInThePeriodIsNamed(): void {
		$register = $this->register();
		$register['EmploymentContract'] = [$register['EmploymentContract'][0], $register['EmploymentContract'][1]];

		$outcome = $this->service($register)->lines(self::FILING);

		$this->assertSame('no-contract', $outcome['findings'][0]['kind']);
		$this->assertSame('e-2', $outcome['findings'][0]['employeeId']);
		$this->assertStringContainsString('Bram Smit', $outcome['findings'][0]['problem']);
	}

	/**
	 * A fund without a scheme in the period makes no lines.
	 *
	 * @return void
	 */
	public function testAFundWithoutASchemeInThePeriodMakesNoLines(): void {
		$outcome = $this->service($this->register())->lines(array_merge(self::FILING, ['fund' => 'abp']));

		$this->assertNull($outcome['scheme']);
		$this->assertSame([], $outcome['lines']);
		$this->assertSame(['no-scheme'], array_column($outcome['findings'], 'kind'));
	}

	/**
	 * A run that is not approved makes no lines.
	 *
	 * @return void
	 */
	public function testARunThatIsNotApprovedMakesNoLines(): void {
		$register = $this->register();
		$register['PayrollRun'][0]['status'] = 'calculated';

		$outcome = $this->service($register)->lines(self::FILING);

		$this->assertSame([], $outcome['lines']);
		$this->assertSame(['run-not-approved'], array_column($outcome['findings'], 'kind'));
	}

	/**
	 * A period that is not a month is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAPeriodThatIsNotAMonthIsRefusedBeforeAnythingIsRead(): void {
		$outcome = $this->service($this->register())->lines(array_merge(self::FILING, ['period' => '2026-Q2']));

		$this->assertSame(['period'], array_column($outcome['findings'], 'kind'));
		$this->assertNull($outcome['scheme']);
	}
}//end class
