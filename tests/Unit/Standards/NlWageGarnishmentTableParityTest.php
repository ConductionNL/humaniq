<?php

/**
 * Table/legacy parity for the converted loonbeslag rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the Payslip floor
 * and Loonbeslag single-active rules of NlWageGarnishmentChecks are declared
 * decision tables, and their pre-conversion predicates stay as the parity
 * oracle (`NlWageGarnishmentChecks::legacyChecks()`). This suite drives both
 * over pinned matrices (beslag reference x net pay vs voet; status x employee
 * x date ranges of a second beslag) and asserts identical verdicts on every
 * fixture.
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlWageGarnishmentChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the loonbeslag rules.
 */
class NlWageGarnishmentTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$beslag = ['status' => 'actief', 'employeeId' => 'emp-1', 'beslagvrijeVoet' => 2950.00, 'effectiveFrom' => '2026-01-01', 'effectiveTo' => null];
		$references = ['lb-1' => 'lb-1', 'padded' => ' lb-1 ', 'blank' => '', 'dangling' => 'lb-9'];
		$netPays = ['above' => 3100.0, 'equal' => 2950.0, 'cent below' => 2949.99, 'far below' => 1200.0, 'string' => '2950', 'absent' => null];

		$payslips = [];
		foreach ($references as $rLabel => $reference) {
			foreach ($netPays as $nLabel => $netPay) {
				$payslips[sprintf('ref=%s net=%s', $rLabel, $nLabel)] = [
					['loonbeslagId' => $reference, 'nettoPay' => $netPay],
					['payroll' => ['loonbeslagenById' => ['lb-1' => $beslag, 'lb-bad' => 'x']]],
				];
			}
		}

		$payslips['no payroll index'] = [['loonbeslagId' => 'lb-1', 'nettoPay' => 100.0], []];

		$ranges = [
			'open' => ['effectiveFrom' => '2026-03-01', 'effectiveTo' => null],
			'before' => ['effectiveFrom' => '2025-01-01', 'effectiveTo' => '2025-12-31'],
			'touching' => ['effectiveFrom' => '2025-06-01', 'effectiveTo' => '2026-01-01'],
			'undated' => ['effectiveFrom' => '', 'effectiveTo' => ''],
		];
		$others = [
			'none' => null,
			'same employee' => ['employeeId' => 'emp-1', 'status' => 'actief'],
			'same employee padded' => ['employeeId' => ' emp-1 ', 'status' => 'actief'],
			'ended' => ['employeeId' => 'emp-1', 'status' => 'afgelost'],
			'other employee' => ['employeeId' => 'emp-2', 'status' => 'actief'],
			'not an array' => 'x',
		];
		$selves = ['actief' => ['status' => 'actief'], 'concept' => ['status' => 'concept'], 'no employee' => ['status' => 'actief', 'employeeId' => ' ']];

		$beslagen = [];
		foreach ($selves as $sLabel => $self) {
			foreach ($ranges as $rLabel => $range) {
				foreach ($others as $oLabel => $other) {
					$object = array_merge($beslag, ['id' => 'lb-1'], $self);
					$index = ['lb-1' => $object];
					if ($other !== null) {
						$index['lb-2'] = is_array($other) === true ? array_merge($beslag, $range, $other) : $other;
					}

					$beslagen[sprintf('%s range=%s other=%s', $sLabel, $rLabel, $oLabel)] = [$object, ['payroll' => ['loonbeslagenById' => $index]]];
				}
			}
		}

		$beslagen['self only via @self'] = [array_merge($beslag, ['@self' => ['id' => 'lb-1']]), ['payroll' => ['loonbeslagenById' => ['lb-1' => $beslag]]]];
		$beslagen['no id, own row indexed'] = [$beslag, ['payroll' => ['loonbeslagenById' => ['lb-1' => $beslag]]]];

		return ['Payslip' => $payslips, 'Loonbeslag' => $beslagen];

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
		$tables = NlWageGarnishmentChecks::tables();
		$legacy = NlWageGarnishmentChecks::legacyChecks();
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
		foreach (NlWageGarnishmentChecks::tables() as $objectType => $specs) {
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

	/**
	 * Only the table form is registered.
	 *
	 * @return void
	 */
	public function testTheRulesAreTableDeclaredOnly(): void {
		$this->assertSame([], NlWageGarnishmentChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
