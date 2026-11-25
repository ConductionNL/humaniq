<?php

/**
 * Table/legacy parity for the converted asset-issue rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the two
 * AssetAssignment rules of NlAssetChecks are declared decision tables, and
 * their pre-conversion predicates stay as the parity oracle
 * (`NlAssetChecks::legacyChecks()`). This suite drives both over one pinned
 * matrix (issue and return dates x asset reference and status x employee x
 * planned offboarding completion) and asserts identical verdicts on every
 * fixture. Dates are relative to today, as the rules read them.
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

use OCA\Humaniq\Standards\Checks\NlAssetChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the asset-issue rules.
 */
class NlAssetTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$dates = [
			'open' => ['issuedOn' => '2026-01-10', 'returnedOn' => null],
			'returned in order' => ['issuedOn' => '2026-01-10', 'returnedOn' => '2026-02-01'],
			'returned same day' => ['issuedOn' => '2026-01-10', 'returnedOn' => '2026-01-10'],
			'returned before issue' => ['issuedOn' => '2026-01-10', 'returnedOn' => '2026-01-09'],
			'bad issue date' => ['issuedOn' => 'ooit', 'returnedOn' => '2026-01-09'],
			'blank return' => ['issuedOn' => '2026-01-10', 'returnedOn' => ' '],
		];
		$assets = ['issued' => 'as-1', 'available' => 'as-2', 'not an array' => 'as-3', 'unknown' => 'as-9', 'blank' => ' '];
		$employeeIds = ['known' => 'emp-1', 'unknown' => 'emp-9', 'blank' => ''];
		$completions = ['future' => $day('+7 days'), 'today' => $day('today'), 'past' => $day('-7 days'), 'unreadable' => 'na de zomer', 'none' => null];

		$assignments = [];
		foreach ($dates as $dLabel => $dateFields) {
			foreach ($assets as $aLabel => $assetId) {
				foreach ($employeeIds as $eLabel => $employeeId) {
					foreach ($completions as $cLabel => $completion) {
						$assignments[sprintf('%s asset=%s employee=%s completion=%s', $dLabel, $aLabel, $eLabel, $cLabel)] = [
							array_merge($dateFields, ['assetId' => $assetId, 'employeeId' => $employeeId]),
							['related' => [
								'Asset' => ['byId' => ['as-1' => ['status' => 'issued'], 'as-2' => ['status' => 'available'], 'as-3' => 'x']],
								'Employee' => ['byId' => ['emp-1' => []]],
								'Offboarding' => ['plannedCompletionByEmployeeId' => ($completion === null ? [] : ['emp-1' => $completion, 'emp-9' => $completion])],
							]],
						];
					}
				}
			}
		}

		$assignments['empty assignment'] = [[], []];

		return ['AssetAssignment' => $assignments];

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
		$tables = NlAssetChecks::tables();
		$legacy = NlAssetChecks::legacyChecks();
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
		foreach (NlAssetChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlAssetChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
