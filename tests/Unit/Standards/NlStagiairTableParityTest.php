<?php

/**
 * Table/legacy parity for the converted BPV-overeenkomst rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the
 * `nl-bpv-overeenkomst-vereist` rule is declared as one decision table per
 * object type (Stagiair, EmploymentContract guarded to bbl), and its
 * pre-conversion predicates stay as the parity oracle
 * (`NlStagiairChecks::legacyChecks()`). This suite drives both paths over a
 * pinned fixture matrix (signed flag true/false/null/truthy string, start
 * dates past/today/future/unreadable/absent, contract types incl.
 * near-misses) and asserts identical verdicts on every fixture.
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

use DateTimeImmutable;
use OCA\Humaniq\Standards\Checks\NlStagiairChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Parity between the table-declared BPV checks and their legacy predicates.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */
class NlStagiairTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix, valid for both object types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$today = new DateTimeImmutable('today');
		$types = ['bbl', 'bol', 'BBL', 'permanent', '', null];
		$signed = ['signed' => true, 'unsigned' => false, 'unset' => null, 'string' => '1'];
		$starts = [
			'past' => $today->modify('-30 days')->format('Y-m-d'),
			'yesterday' => $today->modify('-1 day')->format('Y-m-d'),
			'today' => $today->format('Y-m-d'),
			'future' => $today->modify('+30 days')->format('Y-m-d'),
			'unreadable' => 'binnenkort',
			'blank' => '',
			'absent' => null,
		];

		$fixtures = [];
		foreach ($types as $type) {
			foreach ($signed as $signedLabel => $signedValue) {
				foreach ($starts as $startLabel => $start) {
					$fixtures[sprintf('type=%s %s start=%s', var_export($type, true), $signedLabel, $startLabel)] = [
						'type' => $type,
						'bpvOvereenkomstOndertekend' => $signedValue,
						'startDate' => $start,
					];
				}
			}
		}

		$fixtures['empty object'] = [];

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
	 * Both object types answer identically through the table path and the legacy oracle.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlStagiairChecks::tables();
		$legacy = NlStagiairChecks::legacyChecks();

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
	 * The matrix exercises both verdicts for every object type.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdicts(): void {
		foreach (NlStagiairChecks::tables() as $objectType => $specs) {
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
	public function testTheRuleIsTableDeclaredOnly(): void {
		$this->assertSame([], NlStagiairChecks::checks());

	}//end testTheRuleIsTableDeclaredOnly()

}//end class
