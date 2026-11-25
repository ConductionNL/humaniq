<?php

/**
 * Table/legacy parity for the converted WNT-norm rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the WntDisclosure rule of NlWntChecks is a declared decision table, and
 * its pre-conversion predicate stays as the parity oracle
 * (`NlWntChecks::legacyChecks()`). This suite drives both paths over a
 * pinned matrix (employee resolves or dangles x topfunctionaris flag x
 * exemption reason x compensation far under / far over the loaded norm,
 * absent or unreadable) and asserts identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlWntChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the WNT-norm rule.
 */
class NlWntTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$flags = ['top' => true, 'not-top' => false, 'truthy' => 1, 'absent' => null];
		$exemptions = ['none' => null, 'blank' => '', 'reason' => 'overgangsrecht'];
		$compensations = ['zero' => 0, 'modest' => 85000.0, 'huge' => 99999999.0, 'string' => '400000', 'absent' => null];

		$disclosures = [];
		foreach ($flags as $fLabel => $flag) {
			foreach ($exemptions as $eLabel => $exemption) {
				foreach ($compensations as $cLabel => $compensation) {
					$employee = ['id' => 'emp-1', 'wntTopfunctionaris' => $flag];
					if ($exemption !== null) {
						$employee['wntUitzonderingReden'] = $exemption;
					}

					$disclosures[sprintf('%s exemption=%s comp=%s', $fLabel, $eLabel, $cLabel)] = [
						['employeeId' => 'emp-1', 'totalCompensation' => $compensation],
						['related' => ['Employee' => ['byId' => ['emp-1' => $employee]]]],
					];
				}
			}
		}

		$disclosures['dangling employee'] = [['employeeId' => 'emp-2', 'totalCompensation' => 99999999.0], ['related' => ['Employee' => ['byId' => []]]]];
		$disclosures['padded employee id'] = [['employeeId' => ' emp-1 ', 'totalCompensation' => 99999999.0], ['related' => ['Employee' => ['byId' => ['emp-1' => ['wntTopfunctionaris' => true]]]]]];
		$disclosures['no index'] = [['employeeId' => 'emp-1', 'totalCompensation' => 99999999.0], []];

		return ['WntDisclosure' => $disclosures];

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
		$tables = NlWntChecks::tables();
		$legacy = NlWntChecks::legacyChecks();
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
		foreach (NlWntChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlWntChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
