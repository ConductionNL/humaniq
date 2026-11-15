<?php

/**
 * Golden test for WorkingTimeRules: the rest, shift and break verdicts of every shape of clock.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\WorkingTimeRules;
use PHPUnit\Framework\TestCase;

/**
 * The rest, shift and break verdicts of every shape of clock.
 */
class WorkingTimeRulesGoldenTest extends TestCase {

	/**
	 * Every shape of clock against every rule.
	 *
	 * @return void
	 */
	public function testTheVerdictsForEveryShapeOfClock(): void {
		$index = [
			'emp-1' => [
				'2026-03-09' => ['clockOut' => '2026-03-09T22:00:00Z'],
				'2026-03-10' => ['clockOut' => ''],
				'2026-03-11' => 'not-a-clock',
			],
		];
		$tiers = [
			['minHours' => 5.5, 'requiredBreakMinutes' => 30],
			['minHours' => '10', 'requiredBreakMinutes' => '45'],
			['minHours' => 'x', 'requiredBreakMinutes' => 90],
			['requiredBreakMinutes' => 120],
		];
		$clock = ['employeeId' => 'emp-1', 'date' => '2026-03-10', 'clockIn' => '2026-03-10T09:00:00Z', 'clockOut' => '2026-03-10T17:00:00Z', 'breakMinutes' => 30];
		$cases = [
			'rested' => $clock,
			'rested-exactly' => array_merge($clock, ['clockIn' => '2026-03-10T09:00:00Z']),
			'short-rest' => array_merge($clock, ['clockIn' => '2026-03-10T08:59:00Z']),
			'previous-open' => array_merge($clock, ['date' => '2026-03-11', 'clockIn' => '2026-03-11T06:00:00Z', 'clockOut' => '2026-03-11T20:00:00Z']),
			'previous-odd' => array_merge($clock, ['date' => '2026-03-12', 'clockIn' => '2026-03-12T06:00:00Z', 'clockOut' => '2026-03-12T07:00:00Z']),
			'no-previous' => array_merge($clock, ['date' => '2026-03-20', 'clockIn' => '2026-03-20T05:00:00Z', 'clockOut' => '2026-03-20T19:30:00Z', 'breakMinutes' => 44]),
			'no-employee' => array_merge($clock, ['employeeId' => '']),
			'no-date' => array_merge($clock, ['date' => ' ']),
			'bad-date' => array_merge($clock, ['date' => 'gisteren']),
			'bad-clock-in' => array_merge($clock, ['clockIn' => 'vroeg']),
			'open-clock' => array_merge($clock, ['clockOut' => null]),
			'blank-clock-out' => array_merge($clock, ['clockOut' => '  ']),
			'bad-clock-out' => array_merge($clock, ['clockOut' => 'laat']),
			'padded-clock-out' => array_merge($clock, ['clockOut' => ' 2026-03-10T15:00:00Z ', 'breakMinutes' => 0]),
			'long-shift' => array_merge($clock, ['clockOut' => '2026-03-10T21:00:01Z', 'breakMinutes' => 45]),
			'twelve-hours' => array_merge($clock, ['clockOut' => '2026-03-10T21:00:00Z', 'breakMinutes' => '45']),
			'negative-break' => array_merge($clock, ['clockOut' => '2026-03-10T10:00:00Z', 'breakMinutes' => -5]),
			'no-break' => array_diff_key($clock, ['breakMinutes' => 1]),
			'ten-hours' => array_merge($clock, ['clockOut' => '2026-03-10T19:00:00Z']),
			'just-over-ten' => array_merge($clock, ['clockOut' => '2026-03-10T19:00:01Z']),
		];
		$got = [];
		foreach ($cases as $name => $case) {
			$got[$name] = [
				'rest' => WorkingTimeRules::dailyRestSatisfied($case, $index),
				'restNoIndex' => WorkingTimeRules::dailyRestSatisfied($case, 'none'),
				'shift' => WorkingTimeRules::maxShiftSatisfied($case),
				'pauze' => WorkingTimeRules::pauzeSatisfied($case, $tiers),
				'pauzeNoTiers' => WorkingTimeRules::pauzeSatisfied($case, null),
				'pauzeEmptyTiers' => WorkingTimeRules::pauzeSatisfied($case, []),
			];
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/golden/working-time-rules.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
	}//end testTheVerdictsForEveryShapeOfClock()
}//end class
