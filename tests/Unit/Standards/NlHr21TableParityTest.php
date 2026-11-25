<?php

/**
 * Table/legacy parity for the converted HR21 contract rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two
 * EmploymentContract rules of NlHr21Checks are declared decision tables, and
 * their pre-conversion predicates stay as the parity oracle
 * (`NlHr21Checks::legacyChecks()`). This suite drives both over one pinned
 * matrix (normfunctie reference x mapping verified/unverified, scale and
 * status x contract scale x contract start and end around today) and asserts
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlHr21Checks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the HR21 contract rules.
 */
class NlHr21TableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$normfuncties = [
			'verified 10 actief' => ['caoSchaal' => '10', 'caoSchaalVerified' => true, 'status' => 'actief'],
			'verified 10 vervallen' => ['caoSchaal' => '10', 'caoSchaalVerified' => true, 'status' => 'vervallen'],
			'unverified 10' => ['caoSchaal' => '10', 'caoSchaalVerified' => false, 'status' => 'vervallen'],
			'truthy verified' => ['caoSchaal' => '10', 'caoSchaalVerified' => 1],
			'verified blank scale' => ['caoSchaal' => ' ', 'caoSchaalVerified' => true, 'status' => 'actief'],
			'not an array' => 'x',
		];
		$references = ['HR21-1' => 'HR21-1', 'padded' => ' HR21-1 ', 'blank' => '', 'unknown' => 'HR21-9'];
		$scales = ['10' => '10', 'padded 10' => ' 10 ', '8' => '8', 'absent' => null];
		$periods = [
			'running' => [$day('-100 days'), $day('+100 days')],
			'open-ended' => [$day('-100 days'), ''],
			'not started' => [$day('+5 days'), ''],
			'ended' => [$day('-300 days'), $day('-1 day')],
			'ends today' => [$day('-300 days'), $day('today')],
			'undated' => ['', ''],
			'datetime' => [$day('-1 day') . 'T08:00:00Z', ''],
		];

		$contracts = [];
		foreach ($normfuncties as $nLabel => $normfunctie) {
			foreach ($references as $rLabel => $reference) {
				foreach ($scales as $sLabel => $scale) {
					foreach ($periods as $pLabel => [$start, $end]) {
						$contracts[sprintf('normfunctie=%s ref=%s scale=%s period=%s', $nLabel, $rLabel, $sLabel, $pLabel)] = [
							['normfunctieId' => $reference, 'caoSchaal' => $scale, 'startDate' => $start, 'endDate' => $end],
							['hr21' => ['normfunctiesById' => ['HR21-1' => $normfunctie]]],
						];
					}
				}
			}
		}

		$contracts['no hr21 index'] = [['normfunctieId' => 'HR21-1', 'caoSchaal' => '8'], []];

		return ['EmploymentContract' => $contracts];

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
		$tables = NlHr21Checks::tables();
		$legacy = NlHr21Checks::legacyChecks();
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
		foreach (NlHr21Checks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlHr21Checks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
