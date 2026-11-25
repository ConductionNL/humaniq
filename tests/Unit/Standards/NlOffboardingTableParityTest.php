<?php

/**
 * Table/legacy parity for the converted offboarding rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the four Offboarding
 * rules of NlOffboardingChecks are declared decision tables, and their
 * pre-conversion predicates stay as the parity oracle
 * (`NlOffboardingChecks::legacyChecks()`). This suite drives all four over
 * one pinned matrix (reason x status x bedrag x verlof flag x leave balances x
 * getuigschrift flag x employee end date vs last working day) and asserts
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

use OCA\Humaniq\Standards\Checks\NlOffboardingChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the offboarding rules.
 */
class NlOffboardingTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$reasons = ['opzegging-werkgever', 'einde-contract', 'opzegging-werknemer'];
		$statuses = ['gepland', 'eindafrekening_gereed', 'afgerond'];
		$bedragen = ['amount' => 1250.5, 'zero' => 0, 'negative' => -1, 'string' => '300', 'text' => 'n.v.t.', 'absent' => null];
		$flags = ['yes' => true, 'no' => false, 'truthy' => 1];
		$balances = [
			'open' => ['emp-1' => [['entitledHours' => 160, 'bovenwettelijkHours' => 8, 'usedHours' => 100]]],
			'used up' => ['emp-1' => [['entitledHours' => 160, 'usedHours' => 160]]],
			'overdrawn + open' => ['emp-1' => [['entitledHours' => 10, 'usedHours' => 40], ['entitledHours' => 5, 'usedHours' => 0]]],
			'empty rows' => ['emp-1' => []],
			'none' => [],
		];
		$employees = [
			'end matches' => ['emp-1' => ['endDate' => '2026-06-30']],
			'end differs' => ['emp-1' => ['endDate' => '2026-07-31']],
			'no end' => ['emp-1' => ['endDate' => ' ']],
			'missing' => [],
		];

		$offboardings = [];
		foreach ($reasons as $reason) {
			foreach ($statuses as $status) {
				foreach ($bedragen as $bLabel => $bedrag) {
					foreach ($flags as $fLabel => $flag) {
						foreach ($balances as $balLabel => $balanceIndex) {
							foreach ($employees as $eLabel => $employeeIndex) {
								$offboardings[sprintf('%s %s bedrag=%s flags=%s balance=%s employee=%s', $reason, $status, $bLabel, $fLabel, $balLabel, $eLabel)] = [
									[
										'employeeId' => 'emp-1',
										'reason' => $reason,
										'status' => $status,
										'transitievergoedingBedrag' => $bedrag,
										'verlofsaldoUitbetaald' => $flag,
										'getuigschriftVerstrekt' => $flag,
										'lastWorkingDay' => '2026-06-30',
									],
									['related' => ['LeaveBalance' => ['byEmployeeId' => $balanceIndex], 'Employee' => ['byId' => $employeeIndex]]],
								];
							}
						}
					}
				}
			}
		}

		$offboardings['empty offboarding'] = [[], []];
		$offboardings['closed, no context'] = [['status' => 'afgerond', 'employeeId' => 'emp-1', 'lastWorkingDay' => '2026-06-30'], []];

		return ['Offboarding' => $offboardings];

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
		$tables = NlOffboardingChecks::tables();
		$legacy = NlOffboardingChecks::legacyChecks();
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
		foreach (NlOffboardingChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlOffboardingChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
