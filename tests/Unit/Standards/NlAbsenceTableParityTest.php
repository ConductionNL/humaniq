<?php

/**
 * Table/legacy parity for the converted absence rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the milestone
 * overdue, wage-payment percentage and sick-pay floor rules of
 * NlAbsenceChecks are declared decision tables, and their pre-conversion
 * closures stay as the parity oracle (`NlAbsenceChecks::legacyChecks()`).
 * This suite drives both over one pinned matrix (open and closed cases;
 * milestones done, undone, due today, at the alert edge, beyond it, overdue
 * or unreadable; percentages missing, blank, below, at and above 70; sick-pay
 * payslips with and without a case, numeric or missing figures, year-one
 * minimum-wage floors above and below the statutory floor) and asserts
 * identical verdicts on every fixture. The milestone-derivation rule stays a
 * closure, which the last test pins.
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

use OCA\Humaniq\Standards\Checks\NlAbsenceChecks;
use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the absence rules.
 */
class NlAbsenceTableParityTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleCatalogue::reset();

	}//end setUp()

	/**
	 * The pinned matrix per rule: label => object.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$fixtures = ['nl-wvp-milestone-overdue' => [], 'nl-loondoorbetaling-minimum' => [], 'nl-loondoorbetaling-floor' => []];

		foreach (['gemeld' => 'gemeld', 'hersteld' => 'hersteld', 'default' => null] as $sLabel => $status) {
			foreach (['today' => 'today', 'in 14 days' => '+14 days', 'in 15 days' => '+15 days', 'in 60 days' => '+60 days', 'overdue' => '-10 days', 'unreadable' => null, 'absent' => false] as $dLabel => $due) {
				foreach (['undone' => null, 'done' => 'yes', 'blank done' => ' '] as $kLabel => $done) {
					$case = ['probleemanalyseDue' => $day('+200 days'), 'planVanAanpakDue' => $day('+300 days')];
					if ($status !== null) {
						$case['status'] = $status;
					}

					if ($due !== false) {
						$case['uwv42WeekMeldingDue'] = ($due === null ? 'binnenkort' : $day($due));
					}

					if ($done !== null) {
						$case['uwv42WeekMeldingDone'] = ($done === 'yes' ? $day('-1 day') : $done);
					}

					$fixtures['nl-wvp-milestone-overdue'][sprintf('%s due=%s %s', $sLabel, $dLabel, $kLabel)] = $case;
				}
			}

			foreach (['absent' => null, 'blank' => '', 'zero' => 0, 'below' => 69.99, 'at' => 70, 'string at' => '70', 'above' => 100, 'text' => 'veel'] as $pLabel => $percentage) {
				$case = [];
				if ($status !== null) {
					$case['status'] = $status;
				}

				if ($percentage !== null) {
					$case['loondoorbetalingPercentage'] = $percentage;
				}

				$fixtures['nl-loondoorbetaling-minimum'][sprintf('%s percentage=%s', $sLabel, $pLabel)] = $case;
			}
		}

		foreach (['case' => 'sick-1', 'blank case' => ' ', 'no case' => null] as $cLabel => $caseId) {
			foreach (['3000' => 3000, 'text' => 'veel', 'absent' => null] as $rLabel => $reference) {
				foreach (['at floor' => 2100, 'cent below' => 2099.99, 'above' => 2500, 'year-one floor' => 2200, 'absent' => null] as $pLabel => $paid) {
					foreach (['year two' => [false, null], 'year one under' => [true, 2000], 'year one over' => [true, 2200], 'year one unreadable' => [true, 'wml'], 'truthy year' => [1, 2200]] as $yLabel => [$yearOne, $wml]) {
						$payslip = ['sickPayYearOne' => $yearOne];
						foreach (['sickLeaveCaseId' => $caseId, 'sickPayReferenceWage' => $reference, 'doorbetaaldLoon' => $paid, 'sickPayMinimumWageFloor' => $wml] as $field => $value) {
							if ($value !== null) {
								$payslip[$field] = $value;
							}
						}

						$fixtures['nl-loondoorbetaling-floor'][sprintf('%s reference=%s paid=%s %s', $cLabel, $rLabel, $pLabel, $yLabel)] = $payslip;
					}
				}
			}
		}

		return $fixtures;

	}//end fixtures()

	/**
	 * Every declared rule answers identically through the table path and the
	 * legacy oracle, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlAbsenceChecks::tables();
		$legacy = NlAbsenceChecks::legacyChecks();
		$fixtures = $this->fixtures();

		$this->assertSame(array_map('array_keys', $legacy), array_map('array_keys', $tables), 'every legacy rule has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$ruleId] as $label => $object) {
					$inputs = ($spec['derive'])($object, []);
					$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
					$seen[$verdict === true ? 'pass' : 'fail'] = true;
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object),
						$verdict,
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}

				$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * Only the milestone derivation stays a closure, and no rule is
	 * registered both ways.
	 *
	 * @return void
	 */
	public function testOnlyTheMilestoneDerivationStaysAClosure(): void {
		$this->assertSame(['SickLeaveCase' => ['nl-wvp-milestone-derivation']], array_map('array_keys', NlAbsenceChecks::checks()));
		$this->assertArrayNotHasKey('nl-wvp-milestone-derivation', NlAbsenceChecks::tables()['SickLeaveCase']);

	}//end testOnlyTheMilestoneDerivationStaysAClosure()

}//end class
