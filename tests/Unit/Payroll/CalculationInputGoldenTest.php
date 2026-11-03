<?php

/**
 * Golden test for the gross-to-net calculation input and its path.
 *
 * Runs PayrollCalculator::calculate() under the 2026 tables over a grid of
 * gross wages, tax table colours, the loonheffingskorting election, dates of
 * birth on both sides of the AOW age, periods, Awf and Aof tariffs, Whk
 * percentages, insured and DGA employees, and the 30%-ruling. For every input
 * it records the input's toArray(), its canonical JSON (the stamped
 * engineInputSnapshot), the round trip through fromCanonicalJson(), and every
 * result field in declaration order. Every case is compared with
 * tests/fixtures/golden/calculation-input.json. Set RECORD_GOLDEN=1 to
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\WageTaxElection;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the calculation input: amounts, rounding, field order and
 * the stamped snapshot.
 *
 * No coverage metadata: the path runs the whole pack interpreter.
 */
class CalculationInputGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/calculation-input.json';

	/**
	 * One case per gross wage; each covers the rest of the grid.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach ([0, 150000, 229440, 380000, 612345, 1500000] as $gross) {
			$cases['gross ' . $gross] = [$gross];
		}

		return $cases;

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param int $gross The gross monthly wage in cents.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(int $gross): void {
		$case = 'gross ' . $gross;
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$tables = TaxTables::load('nl-2026');
		$calculator = new PayrollCalculator();
		$actual = [];
		foreach ($this->inputs($gross) as $input) {
			$result = $calculator->calculate($input, $tables);
			$actual[] = [
				'input' => $input->toArray(),
				'snapshot' => $input->toCanonicalJson(),
				'roundTrip' => CalculationInput::fromCanonicalJson($input->toCanonicalJson())->toArray(),
				'result' => get_object_vars($result),
			];
		}

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
		$this->assertTrue($expected === $actualJson, 'Calculation drifted for ' . $case . ' at byte ' . strspn($expected ^ $actualJson, "\0"));

	}//end testGolden()

	/**
	 * Every input of the grid for one gross wage, built with named arguments
	 * and, for the defaults, without them.
	 *
	 * @param int $gross The gross monthly wage in cents.
	 *
	 * @return list<CalculationInput>
	 */
	private function inputs(int $gross): array {
		$inputs = [];
		foreach ([['wit', true], ['wit', false], ['groen', true]] as [$color, $korting]) {
			foreach ([null, '1990-04-12', '1955-01-01', '1959-06-30'] as $dob) {
				foreach (['2026-01', '2026-12'] as $period) {
					foreach ([['low', 'laag', 1.52], ['high', 'hoog', 0.0]] as [$awf, $aof, $whk]) {
						$inputs[] = new CalculationInput(
							wage: new CalculationWage(grossMonthlySalaryCents: $gross, dateOfBirth: $dob, period: $period),
							levies: new CalculationLevies(
								wageTax: new WageTaxElection(taxTableColor: $color, loonheffingskortingToegepast: $korting),
								insurance: new InsuranceTariffs(awfTariff: $awf, aofTariff: $aof, whkPercentage: $whk)
							)
						);
						foreach ([[false, 0.0], [true, 30.0], [false, 27.5]] as [$insured, $ruling]) {
							$inputs[] = new CalculationInput(
								wage: new CalculationWage(grossMonthlySalaryCents: $gross, dateOfBirth: $dob, period: $period, jurisdiction: 'NL'),
								levies: new CalculationLevies(
									wageTax: new WageTaxElection(taxTableColor: $color, loonheffingskortingToegepast: $korting, thirtyPercentRulingRate: $ruling),
									insurance: new InsuranceTariffs(awfTariff: $awf, aofTariff: $aof, whkPercentage: $whk, verzekeringsplichtig: $insured)
								)
							);
						}
					}
				}
			}
		}

		return $inputs;

	}//end inputs()

}//end class
