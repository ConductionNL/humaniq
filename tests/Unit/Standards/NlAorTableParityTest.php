<?php

/**
 * Table/legacy parity for the converted ambtenaar rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two Employee
 * rules of NlAorChecks are declared decision tables, and their
 * pre-conversion predicates stay as the parity oracle
 * (`NlAorChecks::legacyChecks()`). This suite drives both paths over a pinned
 * fixture matrix (regime present/blank/whitespace/absent, oath date
 * present/blank/whitespace/absent, disclosure flag true/false/null/truthy
 * string) and asserts identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlAorChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Parity between the table-declared ambtenaar checks and their legacy predicates.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */
class NlAorTableParityTest extends TestCase {

	/**
	 * The pinned Employee fixture matrix.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$regimes = ['gemeente' => 'gemeente', 'blank' => '', 'whitespace' => '  ', 'absent' => null];
		$oaths = ['date' => '2026-02-01', 'blank' => '', 'whitespace' => ' ', 'absent' => null];
		$disclosures = ['on' => true, 'off' => false, 'unset' => null, 'string' => '1'];

		$fixtures = [];
		foreach ($regimes as $regimeLabel => $regime) {
			foreach ($oaths as $oathLabel => $oath) {
				foreach ($disclosures as $disclosureLabel => $disclosure) {
					$fixtures[sprintf('regime=%s oath=%s melding=%s', $regimeLabel, $oathLabel, $disclosureLabel)] = [
						'publicSectorRegime' => $regime,
						'ambtseedAfgelegdOp' => $oath,
						'nevenwerkzaamhedenGemeld' => $disclosure,
					];
				}
			}
		}

		$fixtures['empty employee'] = [];

		return $fixtures;

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec The declared check.
	 * @param array<string, mixed> $object The fixture.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $object): bool {
		$inputs = ($spec['derive'])($object, ['jurisdiction' => 'NL']);
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
		$tables = NlAorChecks::tables();
		$legacy = NlAorChecks::legacyChecks();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy object type has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			foreach ($this->fixtures() as $label => $object) {
				foreach ($specs as $ruleId => $spec) {
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object),
						$this->tableVerdict($spec, $object),
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
		foreach (NlAorChecks::tables() as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($this->fixtures() as $object) {
					$seen[$this->tableVerdict($spec, $object) === true ? 'pass' : 'fail'] = true;
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
		$this->assertSame([], NlAorChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
