<?php

/**
 * Table behaviour pin for the converted NL leave rules (REQ-RULE-009).
 *
 * The four LeaveBalance rules of NlLeaveChecks were proven against their
 * pre-conversion predicates twice: by the table/legacy parity suite this file
 * replaces, and by an OpenRegister-backed `occ humaniq:rules:audit` over
 * seeded balances that gave identical verdicts through both paths (tasks 6.1).
 * With the legacy predicates retired (tasks 6.2), the pinned fixture matrix
 * now carries the expected verdict per rule itself, so a change to a
 * derivation or a table cell fails here instead of passing against an oracle.
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

use OCA\Humaniq\Standards\Checks\NlLeaveChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * The table-declared leave checks give the pinned verdict on every fixture.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */
class NlLeaveTableBehaviourTest extends TestCase {

	private const MINIMUM = 'nl-verlof-wettelijk-minimum';
	private const SALDO = 'nl-verlof-saldo-niet-negatief';
	private const EXPIRY = 'nl-verlof-vervaltermijn';
	private const BOVENWETTELIJK = 'nl-verlof-bovenwettelijk-niet-negatief';

	/**
	 * The pinned LeaveBalance fixtures, each with its expected verdict per
	 * rule (true = satisfied). The rows are the matrix the parity suite
	 * pinned, plus the shortfall and all-violated rows of the live audit.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, bool>}>
	 */
	public static function fixtures(): array {
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

		// Override on the base balance => [minimum, saldo, expiry, bovenwettelijk].
		$rows = [
			'compliant seeded balance' => [[], [true, true, true, true]],
			'under-granted entitlement (REQ-LVM-005)' => [['contractHoursPerWeek' => 36, 'entitledHours' => 120, 'usedHours' => 16, 'bovenwettelijkHours' => 0], [false, true, true, true]],
			'entitlement exactly 4x weekly' => [['contractHoursPerWeek' => 36, 'entitledHours' => 144], [true, true, true, true]],
			'fractional shortfall' => [['contractHoursPerWeek' => 32.5, 'entitledHours' => 129.5], [false, true, true, true]],
			'no contract-hours snapshot (vacuous minimum)' => [['contractHoursPerWeek' => null, 'entitledHours' => 8], [true, false, true, true]],
			'blank contract-hours snapshot (vacuous minimum)' => [['contractHoursPerWeek' => '', 'entitledHours' => 8], [true, false, true, true]],
			'over-used balance (REQ-LVM-005)' => [['entitledHours' => 128, 'bovenwettelijkHours' => 0, 'usedHours' => 140], [false, false, true, true]],
			'used equals total entitlement' => [['entitledHours' => 100, 'bovenwettelijkHours' => 0, 'usedHours' => 100], [false, true, true, true]],
			'over-use covered by bovenwettelijk' => [['entitledHours' => 160, 'bovenwettelijkHours' => 10, 'usedHours' => 170], [true, true, true, true]],
			'wrong vervaltermijn' => [['expiryDate' => '2026-07-01'], [true, true, false, true]],
			'missing vervaltermijn' => [['expiryDate' => null], [true, true, false, true]],
			'nothing to lapse (vacuous expiry)' => [['entitledHours' => 0, 'expiryDate' => null], [false, false, true, true]],
			'year field absent (vacuous expiry)' => [['year' => null, 'expiryDate' => null], [true, true, true, true]],
			'negative bovenwettelijk (REQ-BUYSELL-003)' => [['bovenwettelijkHours' => -4], [true, true, true, false]],
			'zero bovenwettelijk boundary' => [['bovenwettelijkHours' => 0], [true, true, true, true]],
			'every rule violated' => [['entitledHours' => 100, 'bovenwettelijkHours' => -8, 'usedHours' => 300, 'expiryDate' => '2026-12-31'], [false, false, false, false]],
		];

		$fixtures = [];
		foreach ($rows as $label => [$override, $verdicts]) {
			$fixtures[$label] = [
				array_merge($base, $override),
				array_combine([self::MINIMUM, self::SALDO, self::EXPIRY, self::BOVENWETTELIJK], $verdicts),
			];
		}

		return $fixtures;

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does:
	 * derive, vacuous pass on null, otherwise delegate to the evaluator.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec The declared check.
	 * @param array<string, mixed> $balance The fixture.
	 *
	 * @return bool
	 */
	private static function tableVerdict(array $spec, array $balance): bool {
		$inputs = ($spec['derive'])($balance, ['jurisdiction' => 'NL']);
		if ($inputs === null) {
			return true;
		}

		return TableCheckEvaluator::satisfied($spec['table'], $inputs);

	}//end tableVerdict()

	/**
	 * Each converted rule gives the pinned verdict on the fixture.
	 *
	 * @param array<string, mixed> $balance  The fixture.
	 * @param array<string, bool>  $expected The verdict per rule.
	 *
	 * @dataProvider fixtures
	 *
	 * @return void
	 */
	public function testEveryRuleGivesThePinnedVerdict(array $balance, array $expected): void {
		$tables = NlLeaveChecks::tables()['LeaveBalance'];

		$actual = [];
		foreach ($tables as $ruleId => $spec) {
			$actual[$ruleId] = self::tableVerdict($spec, $balance);
		}

		$this->assertSame($expected, $actual);

	}//end testEveryRuleGivesThePinnedVerdict()

	/**
	 * The pin covers exactly the four declared rules, the engine registers
	 * no closure beside them, and every rule is pinned both satisfied and
	 * violated, so the matrix is not vacuously green.
	 *
	 * @return void
	 */
	public function testThePinCoversEveryRuleOnBothVerdicts(): void {
		$this->assertSame(
			[self::MINIMUM, self::SALDO, self::EXPIRY, self::BOVENWETTELIJK],
			array_keys(NlLeaveChecks::tables()['LeaveBalance'])
		);
		$this->assertSame([], NlLeaveChecks::checks());

		$seen = [];
		foreach (self::fixtures() as [, $expected]) {
			foreach ($expected as $ruleId => $verdict) {
				$seen[$ruleId][$verdict === true ? 'pass' : 'fail'] = true;
			}
		}

		foreach ($seen as $ruleId => $verdicts) {
			$this->assertCount(2, $verdicts, sprintf('%s must be pinned both satisfied and violated', $ruleId));
		}

	}//end testThePinCoversEveryRuleOnBothVerdicts()

}//end class
