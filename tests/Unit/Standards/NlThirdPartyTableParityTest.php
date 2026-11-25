<?php

/**
 * Table/legacy parity for the converted UBD rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two rules of
 * NlThirdPartyChecks are declared decision tables, and their pre-conversion
 * predicates stay as the parity oracle (`NlThirdPartyChecks::legacyChecks()`).
 * This suite drives both paths over a pinned fixture matrix and asserts
 * identical verdicts on every fixture:
 * - payments: payment date (in the year, on the year's last day, malformed,
 *   blank, absent) x audit date (before, on and after 31 January of the next
 *   year, two years on) x report (sent for this administration, sent for
 *   another, none, no `ubd` index at all);
 * - payees: paid/unpaid (by `id` or by `@self.id`) x BSN and date of birth
 *   each present/blank/whitespace/absent.
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

use OCA\Humaniq\Standards\Checks\NlThirdPartyChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the two UBD rules.
 */
class NlThirdPartyTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$paidOn = ['in-year' => '2026-03-10', 'year-end' => '2026-12-31', 'malformed' => '10-03-2026', 'blank' => '', 'absent' => null];
		$today = ['before' => '2027-01-15', 'deadline' => '2027-01-31', 'after' => '2027-02-01', 'later' => '2028-06-01'];
		$reports = [
			'sent' => ['ubd' => ['sent' => ['ADM-001|2026' => true]]],
			'other-administration' => ['ubd' => ['sent' => ['ADM-002|2026' => true]]],
			'none' => ['ubd' => ['sent' => []]],
			'no-index' => [],
		];

		$payments = [];
		foreach ($paidOn as $paidLabel => $date) {
			foreach ($today as $todayLabel => $day) {
				foreach ($reports as $reportLabel => $report) {
					$payments[sprintf('paidOn=%s today=%s report=%s', $paidLabel, $todayLabel, $reportLabel)] = [
						['payeeId' => 'p-1', 'paidOn' => $date, 'amount' => 450.0, 'administrationId' => 'ADM-001'],
						array_merge($report, ['today' => $day]),
					];
				}
			}
		}

		$values = ['present' => 'x', 'blank' => '', 'whitespace' => '  ', 'absent' => null];
		$paid = ['ubd' => ['paidPayees' => ['p-1' => true]]];
		$payees = [];
		foreach (['paid' => $paid, 'unpaid' => ['ubd' => ['paidPayees' => []]], 'no-index' => []] as $paidLabel => $context) {
			foreach ($values as $bsnLabel => $bsn) {
				foreach ($values as $dobLabel => $dob) {
					$payees[sprintf('%s bsn=%s dob=%s', $paidLabel, $bsnLabel, $dobLabel)] = [
						['id' => 'p-1', 'bsn' => ($bsn === 'x' ? '111222333' : $bsn), 'dateOfBirth' => ($dob === 'x' ? '1971-05-14' : $dob)],
						$context,
					];
				}
			}
		}

		$payees['paid via @self, unidentified'] = [['@self' => ['id' => 'p-1']], $paid];
		$payees['paid via @self, identified'] = [['@self' => ['id' => 'p-1'], 'bsn' => '111222333', 'dateOfBirth' => '1971-05-14'], $paid];
		$payees['empty payee'] = [[], $paid];

		return ['ThirdPartyPayment' => $payments, 'ThirdPartyPayee' => $payees];

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
		$tables = NlThirdPartyChecks::tables();
		$legacy = NlThirdPartyChecks::legacyChecks();
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
		foreach (NlThirdPartyChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlThirdPartyChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
