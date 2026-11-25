<?php

/**
 * Table/legacy parity for the converted job-application retention rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two
 * job-application retention rules of NlAtsChecks are declared decision
 * tables, and their pre-conversion predicates stay as the parity oracle
 * (`NlAtsChecks::legacyChecks()`). This suite drives both over one pinned
 * matrix (status x talent-pool opt-in x rejection date x expiry date, exact,
 * off by a day, past, future or unreadable) and asserts identical verdicts on
 * every fixture. Dates are relative to today, as the rules read them.
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

use OCA\Humaniq\Standards\Checks\NlAtsChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the job-application retention rules.
 */
class NlAtsTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$applications = [];
		foreach (['afgewezen', 'nieuw', 'aangenomen', null] as $status) {
			foreach (['opt-in' => true, 'no opt-in' => false, 'truthy' => 1] as $oLabel => $optIn) {
				$rejected = $day('-40 days');
				$exact = (new \DateTimeImmutable($rejected))->modify(($optIn === true ? '+365' : '+28') . ' days')->format('Y-m-d');
				$expiries = ['exact' => $exact, 'off by one' => (new \DateTimeImmutable($exact))->modify('+1 day')->format('Y-m-d'), 'future' => $day('+30 days'), 'today' => $day('today'), 'unreadable' => 'nooit', 'blank' => ' ', 'absent' => null];
				foreach (['rejected' => $rejected, 'unreadable' => 'gisteren', 'blank' => '', 'absent' => null] as $rLabel => $rejectedDate) {
					foreach ($expiries as $eLabel => $expiry) {
						$object = ['talentPoolOptIn' => $optIn, 'rejectedDate' => $rejectedDate, 'retentionExpiryDate' => $expiry];
						if ($status !== null) {
							$object['status'] = $status;
						}

						$applications[sprintf('%s %s rejected=%s expiry=%s', var_export($status, true), $oLabel, $rLabel, $eLabel)] = [$object, []];
					}
				}
			}
		}

		return ['job-application' => $applications];

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
		$tables = NlAtsChecks::tables();
		$legacy = NlAtsChecks::legacyChecks();
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
		foreach (NlAtsChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlAtsChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
