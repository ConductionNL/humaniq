<?php

/**
 * Table/legacy parity for the converted NL leave rules (REQ-RULE-009).
 *
 * The dual-read discipline for rules-onto-or-decision-tables: while the table
 * form of a converted rule is being proven, its pre-conversion predicate stays
 * available as the parity oracle (`NlLeaveChecks::legacyChecks()`), and this
 * suite drives BOTH paths — the declared table through the real shared-Dmn
 * evaluation semantics, and the legacy closure — over the pinned fixture
 * matrix (the seeded compliant balance plus every violation scenario the
 * pre-conversion tests pinned, plus the vacuous-pass edges) and asserts the
 * verdicts are identical for every rule on every fixture. The oracle retires
 * only after an OpenRegister-backed audit run matches the pre-change audit
 * (tasks section 6).
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

use OCA\Humaniq\Standards\Checks\NlLeaveChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Parity between the table-declared checks and their legacy predicates.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */
class NlLeaveTableParityTest extends TestCase {

	/**
	 * The pinned LeaveBalance fixture matrix: the compliant seeded balance
	 * plus every violation and vacuous-pass scenario the pre-conversion tests
	 * pinned, plus boundary rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$base = [
			'employeeId' => 'employee-jansen',
			'year' => 2026,
			'leaveType' => 'holiday',
			'entitledHours' => 160,
			'bovenwettelijkHours' => 40,
			'usedHours' => 56,
			'contractHoursPerWeek' => 40,
			'expiryDate' => '2027-07-01',
		];

		$overrides = [
			'compliant seeded balance' => [],
			'under-granted entitlement (REQ-LVM-005)' => ['contractHoursPerWeek' => 36, 'entitledHours' => 120, 'usedHours' => 16, 'bovenwettelijkHours' => 0],
			'entitlement exactly 4x weekly' => ['contractHoursPerWeek' => 36, 'entitledHours' => 144],
			'no contract-hours snapshot (vacuous)' => ['contractHoursPerWeek' => null, 'entitledHours' => 8],
			'blank contract-hours snapshot (vacuous)' => ['contractHoursPerWeek' => '', 'entitledHours' => 8],
			'over-used balance (REQ-LVM-005)' => ['entitledHours' => 128, 'bovenwettelijkHours' => 0, 'usedHours' => 140],
			'used equals total entitlement' => ['entitledHours' => 100, 'bovenwettelijkHours' => 0, 'usedHours' => 100],
			'wrong vervaltermijn' => ['year' => 2026, 'expiryDate' => '2026-07-01'],
			'missing vervaltermijn' => ['year' => 2026, 'expiryDate' => null],
			'correct vervaltermijn' => ['year' => 2026, 'expiryDate' => '2027-07-01'],
			'nothing to lapse (vacuous)' => ['entitledHours' => 0, 'expiryDate' => null],
			'year field absent (vacuous)' => ['year' => null, 'expiryDate' => null],
			'negative bovenwettelijk (REQ-BUYSELL-003)' => ['bovenwettelijkHours' => -4],
			'zero bovenwettelijk boundary' => ['bovenwettelijkHours' => 0],
		];

		$fixtures = [];
		foreach ($overrides as $label => $override) {
			$fixtures[$label] = array_merge($base, $override);
		}

		return $fixtures;

	}//end fixtures()

	/**
	 * Evaluate one table-declared check spec the way the engine's wrapper
	 * does: derive, vacuous pass on null, otherwise delegate.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec The declared check.
	 * @param array<string, mixed> $balance The fixture.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $balance): bool {
		$inputs = ($spec['derive'])($balance, ['jurisdiction' => 'NL']);
		if ($inputs === null) {
			return true;
		}

		return TableCheckEvaluator::satisfied($spec['table'], $inputs);

	}//end tableVerdict()

	/**
	 * Every converted rule answers identically through the table path and the
	 * legacy oracle, on every pinned fixture.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlLeaveChecks::tables()['LeaveBalance'];
		$legacy = NlLeaveChecks::legacyChecks()['LeaveBalance'];

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');

		foreach ($this->fixtures() as $label => $balance) {
			foreach ($tables as $ruleId => $spec) {
				$this->assertSame(
					(bool)($legacy[$ruleId])($balance),
					$this->tableVerdict($spec, $balance),
					sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label)
				);
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The matrix is not vacuously green: for every rule at least one fixture
	 * violates and at least one satisfies, so agreement is measured on both
	 * verdicts.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdictsPerRule(): void {
		$tables = NlLeaveChecks::tables()['LeaveBalance'];

		foreach ($tables as $ruleId => $spec) {
			$seen = [];
			foreach ($this->fixtures() as $balance) {
				$seen[$this->tableVerdict($spec, $balance) === true ? 'pass' : 'fail'] = true;
			}

			$this->assertSame(
				['fail' => true, 'pass' => true],
				['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)],
				sprintf('%s: the fixture matrix must exercise both verdicts', $ruleId)
			);
		}

	}//end testTheMatrixExercisesBothVerdictsPerRule()

}//end class
