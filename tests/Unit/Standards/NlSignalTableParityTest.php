<?php

/**
 * Table/legacy parity for the converted HR signals (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the contract-expiry,
 * aanzegtermijn, probation-end and BHV-certificate signals of NlSignalChecks
 * are declared decision tables, and their pre-conversion closures stay as the
 * parity oracle (`NlSignalChecks::legacyChecks()`). This suite drives both
 * over one pinned matrix (contract type, start and end relative to today
 * inside, at the edges of and outside each window, successors that follow,
 * overlap or precede, aanzeggingen before, at and after the deadline or
 * unreadable; probation and certificate dates around their windows) and
 * asserts identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlSignalChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the HR signals.
 */
class NlSignalTableParityTest extends TestCase {

	/**
	 * A date relative to today.
	 *
	 * @param string $offset A DateTimeImmutable::modify() offset.
	 *
	 * @return string
	 */
	private static function day(string $offset): string {
		return (new \DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');

	}//end day()

	/**
	 * The EmploymentContract matrix: label => [object, context].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private function contracts(): array {
		$ends = ['in 10 days' => '+10 days', 'in 60 days' => '+60 days', 'in 61 days' => '+61 days', 'today' => 'today', 'yesterday' => '-1 day', 'in 20 days' => '+20 days', 'in 45 days' => '+45 days', 'unreadable' => null];
		$starts = ['year ago' => '-1 year', '3 months ago' => '-3 months', 'unreadable' => null];
		$notices = ['early' => '-3 months', 'late' => '-1 day', 'none' => false, 'unreadable' => true];
		$siblingSets = [
			'none' => [],
			'follows open' => [['id' => 'c2', 'startDate' => self::day('+2 months')]],
			'follows dated' => [['id' => 'c2', 'startDate' => self::day('+2 months'), 'endDate' => self::day('+1 year')]],
			'earlier' => [['id' => 'c0', 'startDate' => self::day('-3 years'), 'endDate' => self::day('-2 years')]],
			'itself' => [['id' => 'c1', 'startDate' => self::day('+2 months')]],
		];

		$fixtures = [];
		foreach (['temporary', 'permanent'] as $type) {
			foreach ($starts as $sLabel => $start) {
				foreach ($ends as $eLabel => $end) {
					foreach ($notices as $nLabel => $notice) {
						foreach ($siblingSets as $bLabel => $siblings) {
							$contract = ['id' => 'c1', 'employeeId' => 'emp-1', 'type' => $type, 'startDate' => ($start === null ? 'ooit' : self::day($start)), 'endDate' => ($end === null ? 'straks' : self::day($end))];
							if ($notice === true) {
								$contract['aanzegdOn'] = 'vorige week';
							} else if ($notice !== false && $end !== null) {
								$contract['aanzegdOn'] = (new \DateTimeImmutable(self::day($end)))->modify($notice)->format('Y-m-d');
							}

							$fixtures[sprintf('%s start=%s end=%s notice=%s siblings=%s', $type, $sLabel, $eLabel, $nLabel, $bLabel)] = [$contract, ['signals' => ['contractsByEmployeeId' => ['emp-1' => $siblings]]]];
						}
					}
				}
			}
		}

		foreach (['in 3 days' => '+3 days', 'in 14 days' => '+14 days', 'in 15 days' => '+15 days', 'yesterday' => '-1 day', 'today' => 'today'] as $pLabel => $probation) {
			$fixtures['probation ' . $pLabel] = [['type' => 'permanent', 'proeftijdEndDate' => self::day($probation)], []];
		}

		$fixtures['probation unreadable'] = [['type' => 'permanent', 'proeftijdEndDate' => 'binnenkort'], []];
		$fixtures['nothing'] = [[], []];

		return $fixtures;

	}//end contracts()

	/**
	 * The BhvCertificering matrix: label => [object, context].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private function certificates(): array {
		$fixtures = ['absent' => [[], []], 'unreadable' => [['certificaatGeldigTot' => 'ooit'], []]];
		foreach (['today' => 'today', 'in 30 days' => '+30 days', 'in 90 days' => '+90 days', 'in 91 days' => '+91 days', 'expired' => '-1 day'] as $label => $offset) {
			$fixtures[$label] = [['certificaatGeldigTot' => self::day($offset)], []];
		}

		return $fixtures;

	}//end certificates()

	/**
	 * Every declared rule answers identically through the table path and the
	 * legacy oracle, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlSignalChecks::tables();
		$legacy = NlSignalChecks::legacyChecks();
		$fixtures = ['EmploymentContract' => $this->contracts(), 'BhvCertificering' => $this->certificates()];

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy object type has a table form and vice versa');
		$this->assertSame([], NlSignalChecks::checks());

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$objectType] as $label => [$object, $context]) {
					$inputs = ($spec['derive'])($object, $context);
					$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
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

	/**
	 * The shared window includes today and its last day, excludes yesterday
	 * and the day after, and treats an unreadable date as out of scope, on
	 * both the table path and the oracle.
	 *
	 * @return void
	 */
	public function testTheWindowIncludesBothEnds(): void {
		$spec = NlSignalChecks::tables()['EmploymentContract']['nl-signaal-proeftijd-eindigt'];
		$legacy = NlSignalChecks::legacyChecks()['EmploymentContract']['nl-signaal-proeftijd-eindigt'];
		foreach (['today' => [false, ['withinWindow' => true]], '+14 days' => [false, ['withinWindow' => true]], '+15 days' => [true, ['withinWindow' => false]], '-1 day' => [true, ['withinWindow' => false]]] as $offset => [$satisfied, $inputs]) {
			$object = ['proeftijdEndDate' => self::day($offset)];
			$this->assertSame($inputs, ($spec['derive'])($object, []), $offset);
			$this->assertSame($satisfied, $legacy($object), $offset);
		}

		$this->assertNull(($spec['derive'])(['proeftijdEndDate' => 'binnenkort'], []));
		$this->assertTrue($legacy(['proeftijdEndDate' => 'binnenkort']));

	}//end testTheWindowIncludesBothEnds()

}//end class
