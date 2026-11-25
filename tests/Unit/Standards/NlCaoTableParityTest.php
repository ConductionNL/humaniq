<?php

/**
 * Table/legacy parity for the converted CAO rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the scale minimum,
 * unknown component and leave minimum rules of NlCaoChecks are declared
 * decision tables, and their pre-conversion closures stay as the parity
 * oracle (`NlCaoChecks::legacyChecks()`). This suite drives both over one
 * pinned matrix against the shipped CAO corpus (verified, placeholder and
 * unknown agreements and scales; salaries and leave below, at and above the
 * minimum; unresolvable employees, non-numeric figures; declared, undeclared
 * and blank components) and asserts identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\CaoRegistry;
use OCA\Humaniq\Standards\Checks\NlCaoChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the CAO rules.
 */
class NlCaoTableParityTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		CaoRegistry::reset();

	}//end setUp()

	/**
	 * An audit context with emp-1's salary and active-contract CAO.
	 *
	 * @param mixed       $salary The grossMonthlySalary, or null for no employee row.
	 * @param string|null $caoId  The CAO, or null for none.
	 *
	 * @return array<string, mixed>
	 */
	private function context(mixed $salary, ?string $caoId): array {
		return [
			'cao' => [
				'employeesById' => ($salary === null ? [] : ['emp-1' => ['id' => 'emp-1', 'grossMonthlySalary' => $salary]]),
				'caoByEmployeeId' => ($caoId === null ? [] : ['emp-1' => $caoId]),
			],
		];

	}//end context()

	/**
	 * The pinned matrix per rule: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$caos = ['generiek' => 'cao-generiek', 'metaal' => 'cao-metaal-techniek', 'voorbeeld' => 'cao-voorbeeld', 'unknown' => 'cao-bestaat-niet', 'blank' => '', 'absent' => null];
		$fixtures = ['nl-cao-minimumloon-schaal' => [], 'nl-cao-component-onbekend' => [], 'nl-cao-verlof-minimum' => []];

		foreach ($caos as $cLabel => $caoId) {
			foreach (['generiek' => 'generiek', 'A' => 'A', 'unknown' => 'Z9', 'blank' => ' ', 'absent' => null] as $sLabel => $schaal) {
				foreach (['below' => 2000.00, 'at' => 2294.40, 'cent below' => 2294.39, 'above' => 2500, 'text' => 'veel', 'string number' => '1999.99', 'no employee' => null] as $pLabel => $salary) {
					$contract = ['employeeId' => 'emp-1'];
					if ($caoId !== null) {
						$contract['cao'] = $caoId;
					}

					if ($schaal !== null) {
						$contract['caoSchaal'] = $schaal;
					}

					$fixtures['nl-cao-minimumloon-schaal'][sprintf('cao=%s scale=%s salary=%s', $cLabel, $sLabel, $pLabel)] = [$contract, $this->context($salary, null)];
				}
			}

			foreach (['declared' => ['ploegentoeslag'], 'mixed' => ['ploegentoeslag', 'bestaat-niet'], 'undeclared' => ['bestaat-niet'], 'blank' => [' ', ''], 'none' => [], 'scalar' => 'ploegentoeslag', 'absent' => null] as $kLabel => $components) {
				$contract = ['employeeId' => 'emp-1'];
				if ($caoId !== null) {
					$contract['cao'] = $caoId;
				}

				if ($components !== null) {
					$contract['caoComponents'] = $components;
				}

				$fixtures['nl-cao-component-onbekend'][sprintf('cao=%s components=%s', $cLabel, $kLabel)] = [$contract, []];
			}

			foreach (['holiday' => 'holiday', 'sick' => 'special', 'absent' => null] as $tLabel => $leaveType) {
				foreach (['40h' => 40, '24.5h' => '24.5', 'blank' => '', 'text' => 'fulltime', 'absent' => null] as $hLabel => $hours) {
					foreach (['short' => [140, 0], 'exact' => [150, 10], 'over' => [160, 5], 'fraction short' => [97.5, 0.25], 'nothing' => [null, null]] as $gLabel => [$entitled, $bovenwettelijk]) {
						$balance = ['employeeId' => 'emp-1'];
						foreach (['leaveType' => $leaveType, 'contractHoursPerWeek' => $hours, 'entitledHours' => $entitled, 'bovenwettelijkHours' => $bovenwettelijk] as $field => $value) {
							if ($value !== null) {
								$balance[$field] = $value;
							}
						}

						$fixtures['nl-cao-verlof-minimum'][sprintf('cao=%s type=%s hours=%s granted=%s', $cLabel, $tLabel, $hLabel, $gLabel)] = [$balance, $this->context(null, $caoId)];
					}
				}
			}
		}

		return $fixtures;

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
	 * All three rules answer identically through the table path and the
	 * legacy oracle, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlCaoChecks::tables();
		$legacy = NlCaoChecks::legacyChecks();
		$fixtures = $this->fixtures();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy object type has a table form and vice versa');
		$this->assertSame([], NlCaoChecks::checks());

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$ruleId] as $label => [$object, $context]) {
					$verdict = $this->tableVerdict($spec, $object, $context);
					$seen[$verdict === true ? 'pass' : 'fail'] = true;
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object, $context),
						$verdict,
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}

				$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

}//end class
