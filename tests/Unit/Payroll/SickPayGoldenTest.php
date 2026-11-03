<?php

/**
 * Golden test for the loondoorbetaling-bij-ziekte calculation path.
 *
 * Runs SickPayCalculator::compute() over a grid of reference wages, partial
 * wages, percentages, year-one and wachtdag flags and contract hours under
 * the 2026 tax tables, and records every field of the result in declaration order plus
 * the Payslip fields PayrollRunService serialises from it. Every case is
 * compared with tests/fixtures/golden/sick-pay.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\SickPayCalculator;
use OCA\Humaniq\Payroll\SickPayCircumstances;
use OCA\Humaniq\Payroll\SickPayInput;
use OCA\Humaniq\Payroll\SickPayHours;
use OCA\Humaniq\Payroll\SickPayTiming;
use OCA\Humaniq\Payroll\SickPayWage;
use OCA\Humaniq\Payroll\SickPayResult;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\PayrollRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden cases for sick pay: amounts, rounding, field order and the
 * serialised Payslip fields.
 *
 * No coverage metadata: the path runs the tax tables and the payroll run
 * service's serialiser too.
 */
class SickPayGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/sick-pay.json';

	/**
	 * One case per tax year and reference wage; each covers the rest of the grid.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['nl-2026'] as $year) {
			foreach ([0, 1, 99999, 150000, 206495, 229440, 233333, 300001, 612345] as $wage) {
				$cases[$year . ' W=' . $wage] = [$year, $wage];
			}
		}

		return $cases;

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $year The tax tables.
	 * @param int $wage The reference wage in cents.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $year, int $wage): void {
		$case = $year . ' W=' . $wage;
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = [];
		$tables = TaxTables::load($year);
		foreach ([0, 50000, 999999] as $partial) {
			foreach ([70.0, 90.0, 100.0, 85.5] as $percentage) {
				foreach ([[true, true, true], [true, true, false], [false, false, true], [true, false, true], [false, true, true]] as [$yearOne, $wachtdag, $first]) {
					foreach ([[36.0, 40.0], [24.0, 40.0], [0.0, 40.0], [40.0, 0.0], [45.0, 38.0]] as [$hours, $fulltime]) {
						$result = $this->compute($tables, [$wage, $partial, $percentage, $yearOne, $wachtdag, $first, $hours, $fulltime]);
						$actual[] = [get_object_vars($result), $this->payslipFields($result)];
					}
				}
			}
		}

		$actual[] = $this->payslipFields(null);
		$actualJson = (string)json_encode($actual, $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode($actualJson, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, $flags);
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$expected = (string)json_encode($golden[$case], $flags);
		$this->assertTrue($expected === $actualJson, 'Sick pay drifted for ' . $case . ' at byte ' . strspn($expected ^ $actualJson, "\0"));

	}//end testGolden()

	/**
	 * Compute one grid point.
	 *
	 * @param TaxTables $tables The tax tables.
	 * @param array{0: int, 1: int, 2: float, 3: bool, 4: bool, 5: bool, 6: float, 7: float} $p W, A, p, yearOne, wachtdag, firstSickDayInPeriod, contract hours, full-time hours.
	 *
	 * @return SickPayResult
	 */
	private function compute(TaxTables $tables, array $p): SickPayResult {
		$input = new SickPayInput(
			wage: new SickPayWage(referenceWageCents: $p[0], aangepastLoonCents: $p[1], loondoorbetalingPercentage: $p[2]),
			circumstances: new SickPayCircumstances(timing: new SickPayTiming(yearOne: $p[3], wachtdag: $p[4], firstSickDayInPeriod: $p[5]), hours: new SickPayHours(contractHoursPerWeek: $p[6], fulltimeHoursPerWeek: $p[7]))
		);

		return (new SickPayCalculator())->compute($input, $tables);

	}//end compute()

	/**
	 * The Payslip fields PayrollRunService serialises from a result.
	 *
	 * @param SickPayResult|null $result The result, or null for no open case.
	 *
	 * @return array<string, mixed>
	 */
	private function payslipFields(?SickPayResult $result): array {
		$service = (new ReflectionClass(PayrollRunService::class))->newInstanceWithoutConstructor();
		$fields = new ReflectionMethod(PayrollRunService::class, 'sickPayFields');

		return $fields->invoke($service, ($result === null ? null : ['id' => 'case-1']), $result);

	}//end payslipFields()

}//end class
