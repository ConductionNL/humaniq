<?php

/**
 * Table/legacy parity for the payroll Employee rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the seven payroll
 * Employee rules, moved out of NlPayrollChecks into NlPayrollEmployeeChecks,
 * are declared decision tables, and their
 * pre-conversion closures stay as the parity oracle
 * (`NlPayrollEmployeeChecks::legacyChecks()`). This suite drives both over one pinned
 * matrix per rule (flags true, false, truthy or absent; retention dates
 * before, at and after the 5-year line, unreadable or blank, with and without
 * an employment end; ruling rates, caps, terms relative to today and salaries
 * around both norms; A1 certificates around 24 months) and asserts identical
 * verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlPayrollEmployeeChecks;
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
		$tables = NlPayrollEmployeeChecks::tables()['Employee'];
		$legacy = NlPayrollEmployeeChecks::legacyChecks()['Employee'];
		$fixtures = $this->fixtures();

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
	 * No Employee rule stays registered as a closure, here or in
	 * NlPayrollChecks.
	 *
	 * @return void
	 */
	public function testTheEmployeeRulesAreTableDeclaredOnly(): void {
		$this->assertSame([], NlPayrollEmployeeChecks::checks());
		$this->assertArrayNotHasKey('Employee', \OCA\Humaniq\Standards\Checks\NlPayrollChecks::checks());

	}//end testTheEmployeeRulesAreTableDeclaredOnly()

}//end class
