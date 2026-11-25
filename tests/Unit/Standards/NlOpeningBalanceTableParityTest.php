<?php

/**
 * Table/legacy parity for the converted opening-balance rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two Payslip
 * rules of NlOpeningBalanceChecks are declared decision tables, and their
 * pre-conversion predicates stay as the parity oracle
 * (`NlOpeningBalanceChecks::legacyChecks()`). This suite drives both over one
 * pinned matrix (payslip period across month, four-week and week grains,
 * unreadable or absent; employee present, blank or other; balance absent,
 * naming a readable or unreadable last period; first-period index matching
 * or not) and asserts identical verdicts on every fixture.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlOpeningBalanceChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the opening-balance rules.
 */
class NlOpeningBalanceTableParityTest extends TestCase {

	/**
	 * The pinned Payslip matrix: label => [payslip, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$periods = ['2026-01', '2026-03', '2026-06', '2026-07', '2026-P01', '2026-P07', '2026-P08', '2026-W01', '2026-W30', '2025-06', 'juni', '', null];
		$employees = ['emp-1' => 'emp-1', 'padded' => ' emp-1 ', 'blank' => '', 'other' => 'emp-2', 'absent' => null];
		$balances = ['none' => null, 'last 2026-06' => '2026-06', 'last 2026-P06' => '2026-P06', 'unreadable last' => 'half juni', 'blank last' => ''];
		$firsts = ['first matches' => true, 'first elsewhere' => false, 'no index' => null];

		$payslips = [];
		foreach ($periods as $period) {
			foreach ($employees as $eLabel => $employeeId) {
				foreach ($balances as $bLabel => $last) {
					foreach ($firsts as $fLabel => $matches) {
						$payslip = [];
						if ($period !== null) {
							$payslip['period'] = $period;
						}

						if ($employeeId !== null) {
							$payslip['employeeId'] = $employeeId;
						}

						$byEmployeeYear = [];
						if ($last !== null) {
							$byEmployeeYear['emp-1'] = [2026 => ['employeeId' => 'emp-1', 'year' => 2026, 'lastPeriodInPrevious' => $last]];
						}

						$firstPeriod = [];
						if ($matches !== null) {
							$firstPeriod['emp-1'] = [2026 => ($matches === true ? $period : '2026-02'), 2025 => ($matches === true ? $period : '2025-02')];
						}

						$context = ['openingBalances' => ['byEmployeeYear' => $byEmployeeYear, 'firstPeriod' => $firstPeriod]];
						$payslips[sprintf('period=%s employee=%s balance=%s %s', var_export($period, true), $eLabel, $bLabel, $fLabel)] = [$payslip, $context];
					}
				}
			}
		}

		$payslips['no context at all'] = [['employeeId' => 'emp-1', 'period' => '2026-07'], []];

		return ['Payslip' => $payslips];

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec    The declared check.
	 * @param array<string, mixed>                                 $object  The fixture.
	 * @param array<string, mixed>                                 $context The evaluation context.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $object, array $context): bool {
		$inputs = ($spec['derive'])($object, $context);
		if ($inputs === null) {
			return true;
		}

		return TableCheckEvaluator::satisfied($spec['table'], $inputs);

	}//end tableVerdict()

	/**
	 * Both rules answer identically through the table path and the legacy oracle.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlOpeningBalanceChecks::tables();
		$legacy = NlOpeningBalanceChecks::legacyChecks();
		$fixtures = $this->fixtures();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy object type has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			foreach ($fixtures[$objectType] as $label => [$object, $context]) {
				foreach ($specs as $ruleId => $spec) {
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object, $context),
						$this->tableVerdict($spec, $object, $context),
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The matrix exercises both verdicts for every rule.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdicts(): void {
		$fixtures = $this->fixtures();
		foreach (NlOpeningBalanceChecks::tables() as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$objectType] as [$object, $context]) {
					$seen[$this->tableVerdict($spec, $object, $context) === true ? 'pass' : 'fail'] = true;
				}

				$this->assertSame(
					['fail' => true, 'pass' => true],
					['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)],
					sprintf('%s/%s: the fixture matrix must exercise both verdicts', $objectType, $ruleId)
				);
			}
		}

	}//end testTheMatrixExercisesBothVerdicts()

}//end class
