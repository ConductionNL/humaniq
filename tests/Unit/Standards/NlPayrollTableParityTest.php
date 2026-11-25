<?php

/**
 * Table/legacy parity for the payroll rules declared as tables (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the seven payroll
 * Employee rules, moved out of NlPayrollChecks into NlEmployeeDocumentChecks
 * and NlThirtyPercentRulingChecks, are declared decision tables, and their
 * pre-conversion closures stay as the parity oracle (each provider's
 * `legacyChecks()`). This suite drives both over one pinned matrix per rule (flags true, false, truthy or absent; retention dates
 * before, at and after the 5-year line, unreadable or blank, with and without
 * an employment end; ruling rates, caps, terms relative to today and salaries
 * around both norms; A1 certificates around 24 months) and asserts identical
 * verdicts on every fixture. The EmploymentContract rules and the
 * withholding-liability clearing on PayrollRun, declared as tables by
 * NlPayrollChecks itself, and the nine Payslip rules of
 * NlPayslipWithholdingChecks and NlPayslipStatementChecks get the same
 * treatment.
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

use OCA\Humaniq\Standards\Checks\NlEmployeeDocumentChecks;
use OCA\Humaniq\Standards\Checks\NlPayrollChecks;
use OCA\Humaniq\Standards\Checks\NlPayslipStatementChecks;
use OCA\Humaniq\Standards\Checks\NlPayslipWithholdingChecks;
use OCA\Humaniq\Standards\Checks\NlThirtyPercentRulingChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the payroll Employee rules.
 */
class NlPayrollTableParityTest extends TestCase {

	/**
	 * Every combination of the given field values; a null value leaves the
	 * field out.
	 *
	 * @param array<string, array<string, mixed>> $fields Field => label => value.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function combinations(array $fields): array {
		$rows = ['' => []];
		foreach ($fields as $field => $values) {
			$next = [];
			foreach ($rows as $label => $row) {
				foreach ($values as $vLabel => $value) {
					$object = $row;
					if ($value !== null) {
						$object[$field] = $value;
					}

					$next[trim($label . ' ' . $field . '=' . $vLabel)] = $object;
				}
			}

			$rows = $next;
		}

		return $rows;

	}//end combinations()

	/**
	 * The pinned Employee matrix per rule: label => object.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function fixtures(): array {
		$day = static fn (string $offset): string => (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
		$flags = ['true' => true, 'false' => false, 'truthy' => 1, 'absent' => null];
		$ends = ['2020' => '2020-06-30', 'none' => null, 'blank' => ''];
		$retained = ['line' => '2025-12-31', 'before line' => '2025-12-30', 'later' => '2030-01-01', 'unreadable' => 'altijd', 'blank' => ' ', 'absent' => null];

		return [
			'nl-id-bewaarplicht-5jaar' => self::combinations(['identityDocumentVerified' => $flags, 'endDate' => $ends, 'identityDocumentRetainedUntil' => $retained]),
			'nl-loonbelastingverklaring-bewaarplicht-5jaar' => self::combinations(['loonheffingenVerklaringOnFile' => $flags, 'endDate' => $ends, 'loonheffingenVerklaringRetainedUntil' => $retained]),
			'nl-30-percent-regeling' => self::combinations(['thirtyPercentRulingGranted' => $flags, 'thirtyPercentRulingRate' => ['30' => 30, '30.01' => 30.01, '27' => '27', 'blank' => '', 'text' => 'dertig', 'absent' => null]]),
			'nl-30-regeling-aftoppingsgrens' => self::combinations(['thirtyPercentRulingGranted' => $flags, 'thirtyPercentCappedAtWntNorm' => $flags]),
			'nl-30-regeling-looptijd-5jaar' => self::combinations([
				'thirtyPercentRulingGranted' => $flags,
				'thirtyPercentRulingStartDate' => ['2 years ago' => $day('-2 years'), '6 years ago' => $day('-6 years'), 'unreadable' => 'ooit', 'absent' => null],
				'thirtyPercentRulingEndDate' => ['in a year' => $day('+1 year'), 'in 3 years' => $day('+3 years'), 'in 4 years' => $day('+4 years'), 'yesterday' => $day('-1 day'), 'unreadable' => 'later', 'absent' => null],
			]),
			'nl-30-regeling-salarisnorm' => self::combinations([
				'thirtyPercentRulingGranted' => $flags,
				'thirtyPercentRulingReducedNormApplies' => $flags,
				'grossMonthlySalary' => ['at norm' => 48013 / 12, 'below norm' => 4000, 'below reduced' => 3000, 'between' => 3100, 'text' => 'veel', 'absent' => null],
			]),
			'eu-a1-posted-worker' => self::combinations([
				'postedWorker' => $flags,
				'a1CertificateNumber' => ['number' => 'A1-123', 'blank' => ' ', 'absent' => null],
				'startDate' => ['2026-01-01' => '2026-01-01', 'unreadable' => 'binnenkort', 'absent' => null],
				'a1ValidUntil' => ['24 months' => '2028-01-01', '25 months' => '2028-02-01', 'before start' => '2025-12-31', 'unreadable' => 'nooit', 'absent' => null],
			]),
		];

	}//end fixtures()

	/**
	 * Every Employee rule answers identically through the table path and the
	 * legacy oracle, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlEmployeeDocumentChecks::tables()['Employee'] + NlThirtyPercentRulingChecks::tables()['Employee'];
		$legacy = NlEmployeeDocumentChecks::legacyChecks()['Employee'] + NlThirtyPercentRulingChecks::legacyChecks()['Employee'];
		$fixtures = $this->fixtures();
		ksort($tables);
		ksort($legacy);
		ksort($fixtures);

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');
		$this->assertSame(array_keys($fixtures), array_keys($tables), 'every rule has a fixture matrix');

		foreach ($tables as $ruleId => $spec) {
			$seen = [];
			foreach ($fixtures[$ruleId] as $label => $object) {
				$inputs = ($spec['derive'])($object, []);
				$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
				$seen[$verdict === true ? 'pass' : 'fail'] = true;
				$this->assertSame((bool)($legacy[$ruleId])($object), $verdict, sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label));
			}

			$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * No Employee rule stays registered as a closure, in either provider
	 * or in NlPayrollChecks.
	 *
	 * @return void
	 */
	public function testTheEmployeeRulesAreTableDeclaredOnly(): void {
		$this->assertSame([], NlEmployeeDocumentChecks::checks());
		$this->assertSame([], NlThirtyPercentRulingChecks::checks());
		$this->assertArrayNotHasKey('Employee', NlPayrollChecks::checks());

	}//end testTheEmployeeRulesAreTableDeclaredOnly()

	/**
	 * The EmploymentContract and PayrollRun rules NlPayrollChecks declares as
	 * tables answer identically to their closures, and each matrix reaches
	 * both verdicts.
	 *
	 * @return void
	 */
	public function testTheContractAndRunTablesAgreeWithTheirClosures(): void {
		$fixtures = [
			'EmploymentContract' => self::combinations([
				'type' => ['permanent' => 'permanent', 'bbl' => 'bbl', 'temporary' => 'temporary', 'absent' => null],
				'writtenContract' => ['written' => true, 'not written' => false],
				'bpvOvereenkomstOndertekend' => ['signed' => true, 'absent' => null],
				'awfTariff' => ['low' => 'low', 'high' => 'high', 'blank' => ' ', 'absent' => null],
				'hourlyWage' => ['14.71' => 14.71, '14.70' => '14.70', '25' => 25, 'text' => 'veel', 'absent' => null],
				'hoursPerWeek' => ['36' => 36, 'zero' => 0, 'negative' => -4, 'text' => 'fulltime', 'absent' => null],
			]),
			'PayrollRun' => self::combinations([
				'status' => ['posted' => 'posted', 'paid' => 'paid', 'approved' => 'approved', 'absent' => null],
				'withholdingsClearedToZero' => ['cleared' => true, 'not cleared' => false, 'truthy' => 1, 'absent' => null],
				'withholdingLiabilityBalance' => ['zero' => 0, 'half cent' => 0.004, 'cent' => 0.01, 'negative' => '-12.50', 'text' => 'nul', 'absent' => null],
			]),
		];
		$tables = NlPayrollChecks::tables();
		$legacy = NlPayrollChecks::legacyChecks();

		$this->assertSame(array_map('array_keys', $legacy), array_map('array_keys', $tables), 'every legacy rule has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$objectType] as $label => $object) {
					$inputs = ($spec['derive'])($object, []);
					$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
					$seen[$verdict === true ? 'pass' : 'fail'] = true;
					$this->assertSame((bool)($legacy[$objectType][$ruleId])($object), $verdict, sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label));
				}

				$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
			}
		}

		$this->assertSame(['xc-payroll-gl-reconciliation'], array_keys(NlPayrollChecks::checks()['PayrollRun']), 'the GL reconciliation stays a closure');
		$this->assertArrayNotHasKey('EmploymentContract', NlPayrollChecks::checks());

	}//end testTheContractAndRunTablesAgreeWithTheirClosures()

	/**
	 * The nine Payslip rules NlPayslipWithholdingChecks and
	 * NlPayslipStatementChecks declare as tables answer identically to their
	 * closures, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testThePayslipTablesAgreeWithTheirClosures(): void {
		$amounts = ['zero' => 0, 'positive' => 412.5, 'string' => '99.99', 'negative' => -0.01, 'blank' => '', 'text' => 'geen', 'absent' => null];
		$fixtures = [
			'nl-loonheffingen-inhouding' => self::combinations(['loonheffing' => $amounts]),
			'nl-werknemersverzekeringen-werkgever' => self::combinations(['werknemersverzekeringen' => $amounts]),
			'nl-loonheffingen-volksverzekeringen' => self::combinations(['loonheffing' => ['500' => 500, '100' => '100', 'negative' => -1, 'text' => 'x', 'absent' => null], 'volksverzekeringen' => ['100' => 100, '500' => 500, '500.01' => 500.01, 'negative' => -5, 'absent' => null]]),
			'nl-anoniementarief' => self::combinations(['anoniementariefApplied' => ['applied' => true, 'not applied' => false, 'truthy' => 1, 'absent' => null], 'appliedTaxRate' => ['52' => 52, '52.004' => 52.004, '51.99' => 51.99, '60' => '60', '36.97' => 36.97, 'blank' => '', 'absent' => null]]),
			'nl-wkr-vrije-ruimte' => self::combinations(['wkrVrijeRuimteRemaining' => ['positive' => 120, 'zero' => 0, 'negative' => -40, 'text' => 'op', 'absent' => null], 'wkrExcess' => ['40' => 40, 'zero' => 0, 'text' => 'x', 'absent' => null]]),
			'nl-wkr-eindheffing-80' => self::combinations(['wkrExcess' => ['40' => 40, 'zero' => 0, 'negative' => -1, 'text' => 'veel', 'absent' => null], 'wkrEindheffingRate' => ['80' => 80, '80.004' => '80.004', '75' => 75, 'blank' => '', 'absent' => null]]),
			'nl-loonstrook-inhoud' => self::combinations(['showsGrossWage' => ['yes' => true, 'no' => false], 'showsDeductionBasis' => ['yes' => true, 'truthy' => 1], 'showsMinimumWage' => ['yes' => true, 'absent' => null], 'showsEmployerEmployeeIds' => ['yes' => true, 'no' => false]]),
		];
		foreach (['nl-zvw-werkgeversheffing', 'nl-zvw-inhouding'] as $ruleId) {
			$fixtures[$ruleId] = self::combinations(['zvwMode' => ['werkgeversheffing' => 'werkgeversheffing', 'inhouding' => 'inhouding', 'absent' => null], 'zvwRate' => ['6.10' => 6.1, '4.85' => '4.85', '6.11' => 6.11, 'text' => 'x', 'absent' => null], 'zvw' => ['amount' => 120.5, 'blank' => '', 'absent' => null]]);
		}

		$tables = NlPayslipWithholdingChecks::tables()['Payslip'] + NlPayslipStatementChecks::tables()['Payslip'];
		$legacy = NlPayslipWithholdingChecks::legacyChecks()['Payslip'] + NlPayslipStatementChecks::legacyChecks()['Payslip'];
		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');
		$this->assertSame([], array_diff(array_keys($tables), array_keys($fixtures)), 'every rule has a fixture matrix');

		foreach ($tables as $ruleId => $spec) {
			$seen = [];
			foreach ($fixtures[$ruleId] as $label => $object) {
				$inputs = ($spec['derive'])($object, []);
				$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
				$seen[$verdict === true ? 'pass' : 'fail'] = true;
				$this->assertSame((bool)($legacy[$ruleId])($object), $verdict, sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label));
			}

			$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
		}

		$this->assertSame([], NlPayslipWithholdingChecks::checks());
		$this->assertSame([], NlPayslipStatementChecks::checks());
		$this->assertSame(['nl-awf-herziening-uren-signaal', 'nl-vakantiebijslag-8procent', 'nl-30-regeling-aftoppingsgrens-bedrag'], array_keys(NlPayrollChecks::checks()['Payslip']), 'the three recomputing Payslip rules stay closures');

	}//end testThePayslipTablesAgreeWithTheirClosures()

}//end class
