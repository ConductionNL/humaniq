<?php

/**
 * Table/legacy parity for the converted ABP fund rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the PayrollRun rule of NlAbpChecks is a declared decision table, and its
 * pre-conversion predicate stays as the parity oracle
 * (`NlAbpChecks::legacyChecks()`). This suite drives both paths over a
 * pinned matrix (jurisdiction x status x period x administration x
 * ABP-plichtig x filed) and asserts identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlAbpChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the ABP fund rule.
 */
class NlAbpTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$jurisdictions = ['NL' => 'NL', 'nl' => 'nl', 'DE' => 'DE', 'absent' => null];
		$statuses = ['approved', 'posted', 'paid', 'draft', ''];
		$periods = ['2026-05' => '2026-05', 'blank' => '', 'padded' => ' 2026-05 '];
		$administrations = ['ADM-1' => 'ADM-1', 'blank' => ''];
		$plichtig = ['yes' => ['ADM-1' => true], 'no' => ['ADM-1' => false], 'truthy' => ['ADM-1' => 1], 'absent' => []];
		$filed = ['filed' => ['ADM-1' => ['2026-05' => true]], 'not-filed' => ['ADM-1' => ['2026-05' => false]], 'absent' => []];

		$runs = [];
		foreach ($jurisdictions as $jLabel => $jurisdiction) {
			foreach ($statuses as $status) {
				foreach ($periods as $pLabel => $period) {
					foreach ($administrations as $aLabel => $administrationId) {
						foreach ($plichtig as $plLabel => $plichtigIndex) {
							foreach ($filed as $fLabel => $filedIndex) {
								$runs[sprintf('%s %s period=%s adm=%s plichtig=%s %s', $jLabel, $status, $pLabel, $aLabel, $plLabel, $fLabel)] = [
									['jurisdiction' => $jurisdiction, 'status' => $status, 'period' => $period, 'administrationId' => $administrationId],
									['related' => ['Administration' => ['abpPlichtigByAdministrationId' => $plichtigIndex], 'PensionFiling' => ['abpFiledPeriodsByAdministrationId' => $filedIndex]]],
								];
							}
						}
					}
				}
			}
		}

		$runs['no indexes'] = [['jurisdiction' => 'NL', 'status' => 'approved', 'period' => '2026-05', 'administrationId' => 'ADM-1'], []];

		return ['PayrollRun' => $runs];

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
		$tables = NlAbpChecks::tables();
		$legacy = NlAbpChecks::legacyChecks();
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
		foreach (NlAbpChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlAbpChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
