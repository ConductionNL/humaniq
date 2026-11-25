<?php

/**
 * Table/legacy parity for the converted net-pay IBAN rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the Payslip rule of NlNetPayChecks is a declared decision table, and its
 * pre-conversion predicate stays as the parity oracle
 * (`NlNetPayChecks::legacyChecks()`). This suite drives both paths over a
 * pinned matrix (period payable/not/no index x employee key present, blank,
 * whitespace, absent x IBAN on file true/false/truthy/unresolved) and asserts
 * identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlNetPayChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the net-pay IBAN rules.
 */
class NlNetPayTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$periods = ['payable' => ['2026-05'], 'other' => ['2026-04'], 'none' => []];
		$keys = ['emp-1' => 'emp-1', 'padded' => ' emp-1 ', 'blank' => '', 'whitespace' => '  ', 'absent' => null];
		$ibans = ['on-file' => ['emp-1' => true], 'missing' => ['emp-1' => false], 'truthy' => ['emp-1' => 1], 'unresolved' => []];

		$payslips = [];
		foreach ($periods as $periodLabel => $payable) {
			foreach ($keys as $keyLabel => $key) {
				foreach ($ibans as $ibanLabel => $iban) {
					$payslips[sprintf('period=%s employee=%s iban=%s', $periodLabel, $keyLabel, $ibanLabel)] = [
						['period' => '2026-05', 'employeeId' => $key],
						['netpay' => ['payablePeriods' => $payable, 'ibanByEmployeeKey' => $iban]],
					];
				}
			}
		}

		$payslips['no netpay index'] = [['period' => '2026-05', 'employeeId' => 'emp-1'], []];
		$payslips['empty payslip, payable empty period'] = [[], ['netpay' => ['payablePeriods' => [''], 'ibanByEmployeeKey' => []]]];

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
		$tables = NlNetPayChecks::tables();
		$legacy = NlNetPayChecks::legacyChecks();
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
		foreach (NlNetPayChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlNetPayChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
