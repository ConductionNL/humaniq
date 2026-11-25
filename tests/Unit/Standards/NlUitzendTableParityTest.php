<?php

/**
 * Table/legacy parity for the converted agency-contract rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two
 * EmploymentContract rules of NlUitzendChecks are declared decision tables,
 * and their pre-conversion predicates stay as the parity oracle
 * (`NlUitzendChecks::legacyChecks()`). This suite drives both paths over a
 * pinned fixture matrix (contract types incl. near-misses, the beding flag as
 * true/false/null/truthy string, every fase, wage and reference variants) and
 * asserts identical verdicts for both rules on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlUitzendChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Parity between the table-declared agency checks and their legacy predicates.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */
class NlUitzendTableParityTest extends TestCase {

	/**
	 * The pinned EmploymentContract fixture matrix.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$types = ['agency', 'permanent', 'Agency', ' agency', '', null];
		$beding = ['on' => true, 'off' => false, 'unset' => null, 'string' => '1'];
		$fases = ['A', 'B', 'C', 'a', '', null];
		$wages = ['wage' => 21.5, 'string-wage' => '21.50', 'no-wage' => null, 'text-wage' => 'n.v.t.'];
		$references = ['ref' => 'NBBU-CAO-2026', 'blank' => '', 'whitespace' => '  ', 'none' => null];

		$fixtures = [];
		foreach ($types as $type) {
			foreach ($beding as $bedingLabel => $bedingValue) {
				foreach ($fases as $fase) {
					$fixtures[sprintf('type=%s beding=%s fase=%s', var_export($type, true), $bedingLabel, var_export($fase, true))] = [
						'type' => $type,
						'uitzendbedingVanToepassing' => $bedingValue,
						'uitzendFase' => $fase,
						'hourlyWage' => 21.5,
						'inlenersbeloningReferentie' => 'NBBU-CAO-2026',
					];
				}
			}

			foreach ($wages as $wageLabel => $wage) {
				foreach ($references as $referenceLabel => $reference) {
					$fixtures[sprintf('type=%s %s reference=%s', var_export($type, true), $wageLabel, $referenceLabel)] = [
						'type' => $type,
						'uitzendbedingVanToepassing' => false,
						'hourlyWage' => $wage,
						'inlenersbeloningReferentie' => $reference,
					];
				}
			}
		}

		$fixtures['empty contract'] = [];

		return $fixtures;

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec The declared check.
	 * @param array<string, mixed> $contract The fixture.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $contract): bool {
		$inputs = ($spec['derive'])($contract, ['jurisdiction' => 'NL']);
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
		$tables = NlUitzendChecks::tables()['EmploymentContract'];
		$legacy = NlUitzendChecks::legacyChecks()['EmploymentContract'];

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');

		foreach ($this->fixtures() as $label => $contract) {
			foreach ($tables as $ruleId => $spec) {
				$this->assertSame(
					(bool)($legacy[$ruleId])($contract),
					$this->tableVerdict($spec, $contract),
					sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label)
				);
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The matrix exercises both verdicts for every rule.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdictsPerRule(): void {
		foreach (NlUitzendChecks::tables()['EmploymentContract'] as $ruleId => $spec) {
			$seen = [];
			foreach ($this->fixtures() as $contract) {
				$seen[$this->tableVerdict($spec, $contract) === true ? 'pass' : 'fail'] = true;
			}

			$this->assertSame(
				['fail' => true, 'pass' => true],
				['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)],
				sprintf('%s: the fixture matrix must exercise both verdicts', $ruleId)
			);
		}

	}//end testTheMatrixExercisesBothVerdictsPerRule()

	/**
	 * Only the table form is registered.
	 *
	 * @return void
	 */
	public function testTheRulesAreTableDeclaredOnly(): void {
		$this->assertSame([], NlUitzendChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
