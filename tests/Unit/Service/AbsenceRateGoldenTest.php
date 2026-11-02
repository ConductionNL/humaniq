<?php

/**
 * Golden test for AbsenceRateService::absenceRate().
 *
 * Runs the rate over fixed cases, contracts, working patterns, non-working
 * times and calendar dates. The cases take every branch of the calculation:
 * no contract, a blank or missing employee id, an unusable or zero FTE, a
 * contract outside the period, two contracts for one employee, open,
 * recovered and reopened cases, progression steps, a pattern for an employee
 * with and without a contract, a non-default full-time week, and an unread
 * working calendar. Every result is compared with
 * tests/fixtures/golden/absence-rate.json. Set RECORD_GOLDEN=1 to rewrite
 * that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\AbsencePeriod;
use OCA\Humaniq\Service\AbsenceRateService;
use OCA\Humaniq\Service\WorkingTimeSources;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the FTE-weighted absence rate.
 *
 * @covers \OCA\Humaniq\Service\AbsenceRateService
 * @uses   \OCA\Humaniq\Service\AbsencePeriod
 * @uses   \OCA\Humaniq\Service\WorkingTimeSources
 * @uses   \OCA\Humaniq\Service\AbsenceProgression
 * @uses   \OCA\Humaniq\Service\WorkingHoursService
 * @uses   \OCA\Humaniq\Service\PatternDayEquivalents
 *
 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-001
 */
class AbsenceRateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/absence-rate.json';

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = json_encode($this->rate(self::scenarios()[$case]), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Run one scenario through the rate.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function rate(array $s): array {
		$from = new DateTimeImmutable($s['from']);
		$to = new DateTimeImmutable($s['to']);
		$hours = ($s['fullTime'] ?? AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK);
		$service = new AbsenceRateService();

		return $service->absenceRate(
			$s['cases'] ?? [],
			$s['contracts'] ?? [],
			new AbsencePeriod($from, $to, $hours),
			new WorkingTimeSources($s['patterns'] ?? [], $s['nonWorking'] ?? [], $s['dates'] ?? null)
		);

	}//end rate()

	/**
	 * A contract row.
	 *
	 * @param mixed $employeeId The employee id.
	 * @param mixed $hours Hours per week.
	 * @param string|null $start Start date.
	 * @param string|null $end End date.
	 *
	 * @return array<string, mixed>
	 */
	private static function contract(mixed $employeeId, mixed $hours = 40, ?string $start = '2025-01-01', ?string $end = null): array {
		return ['employeeId' => $employeeId, 'hoursPerWeek' => $hours, 'startDate' => $start, 'endDate' => $end];

	}//end contract()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$jan = ['from' => '2026-01-01', 'to' => '2026-01-31'];
		$pattern = ['employeeId' => 'e1', 'validFrom' => '2025-01-01', 'validUntil' => null, 'hoursMonday' => 8, 'hoursTuesday' => 8, 'hoursWednesday' => 8];
		$open = ['employeeId' => 'e1', 'firstSickDay' => '2026-01-10', 'status' => 'gemeld'];

		return [
			'empty' => $jan,
			'no contract' => ($jan + ['cases' => [$open]]),
			'one full absence' => ($jan + ['cases' => [['employeeId' => 'e1', 'firstSickDay' => '2025-12-01', 'status' => 'gemeld']], 'contracts' => [self::contract('e1')]]),
			'open case mid month' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1'), self::contract('e2', 20)]]),
			'recovered' => ($jan + ['cases' => [['employeeId' => 'e1', 'firstSickDay' => '2026-01-05', 'recoveredDate' => '2026-01-12', 'status' => 'hersteld']], 'contracts' => [self::contract('e1', 32)]]),
			'stale recovered date on open case' => ($jan + ['cases' => [['employeeId' => 'e1', 'firstSickDay' => '2026-01-05', 'recoveredDate' => '2026-01-12', 'status' => 'gemeld']], 'contracts' => [self::contract('e1', 32)]]),
			'case before period' => ($jan + ['cases' => [['employeeId' => 'e1', 'firstSickDay' => '2025-11-01', 'recoveredDate' => '2025-11-20', 'status' => 'hersteld']], 'contracts' => [self::contract('e1')]]),
			'case after period' => ($jan + ['cases' => [['employeeId' => 'e1', 'firstSickDay' => '2026-02-03', 'status' => 'gemeld']], 'contracts' => [self::contract('e1')]]),
			'missing first sick day' => ($jan + ['cases' => [['employeeId' => 'e1', 'status' => 'gemeld']], 'contracts' => [self::contract('e1')]]),
			'progression steps' => ($jan + [
				'cases' => [[
					'employeeId' => 'e1', 'firstSickDay' => '2025-12-01', 'status' => 'gemeld',
					'absenceProgression' => [
						['effectiveFrom' => '2025-12-01', 'absencePercentage' => 100],
						['effectiveFrom' => '2026-01-16', 'absencePercentage' => 40],
						['effectiveFrom' => '2026-01-25', 'absencePercentage' => 0],
					],
				]],
				'contracts' => [self::contract('e1', 36)],
			]),
			'blank and missing employee ids' => ($jan + ['cases' => [['employeeId' => ' ', 'firstSickDay' => '2026-01-02'], ['firstSickDay' => '2026-01-02'], ['employeeId' => 7, 'firstSickDay' => '2026-01-02']], 'contracts' => [self::contract(' '), self::contract(null, 20), self::contract('e1')]]),
			'unusable hours' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 'veel'), self::contract('e2', -8), self::contract('e3', 0)]]),
			'contract outside period' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 40, '2026-02-01'), self::contract('e2', 40, '2024-01-01', '2025-12-31')]]),
			'contract partly in period' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 40, '2026-01-15'), self::contract('e2', 30, null, '2026-01-20')]]),
			'two contracts one employee' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 16), self::contract('e1', 16, '2026-01-10')]]),
			'non default full-time week' => ($jan + ['fullTime' => 36.0, 'cases' => [$open], 'contracts' => [self::contract('e1', 36), self::contract('e2', 18)]]),
			'zero full-time week' => ($jan + ['fullTime' => 0.0, 'cases' => [$open], 'contracts' => [self::contract('e1')]]),
			'pattern' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 24), self::contract('e2', 40)], 'patterns' => [$pattern], 'dates' => ['2026-01-01']]),
			'pattern unread calendar' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 24)], 'patterns' => [$pattern]]),
			'pattern with non-working time' => ($jan + [
				'fullTime' => 38.0,
				'cases' => [$open],
				'contracts' => [self::contract('e1', 24)],
				'patterns' => [$pattern],
				'nonWorking' => [['employeeId' => 'e1', 'startDate' => '2026-01-19', 'endDate' => '2026-01-21', 'hoursNotWorked' => 8]],
				'dates' => [],
			]),
			'pattern without contract' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e2')], 'patterns' => [$pattern, ['employeeId' => '', 'validFrom' => '2025-01-01', 'hoursMonday' => 8]]]),
			'two patterns, one contracted' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 24), self::contract(' ', 40)], 'patterns' => [$pattern, ['employeeId' => 'e9', 'validFrom' => '2025-01-01', 'hoursFriday' => 8]], 'dates' => []]),
			'pattern calendar day off' => ($jan + ['cases' => [$open], 'contracts' => [self::contract('e1', 24)], 'patterns' => [$pattern], 'dates' => ['2026-01-05', '2026-01-13']]),
			'case on an ended contract' => ($jan + ['cases' => [['employeeId' => 'e2', 'firstSickDay' => '2025-12-20', 'status' => 'gemeld']], 'contracts' => [self::contract('e1'), self::contract('e2', 40, '2024-01-01', '2025-12-31')]]),
			'pattern progression' => ($jan + [
				'cases' => [[
					'employeeId' => 'e1', 'firstSickDay' => '2026-01-10', 'recoveredDate' => '2026-01-23', 'status' => 'hersteld',
					'absenceProgression' => [['effectiveFrom' => '2026-01-10', 'absencePercentage' => 100], ['effectiveFrom' => '2026-01-14', 'absencePercentage' => 50]],
				]],
				'contracts' => [self::contract('e1', 24)],
				'patterns' => [$pattern],
				'dates' => [],
			]),
			'pattern case outside period' => ($jan + ['cases' => [['employeeId' => 'e1', 'firstSickDay' => '2026-02-02', 'status' => 'gemeld'], $open], 'contracts' => [self::contract('e1', 24)], 'patterns' => [$pattern], 'dates' => []]),
			'single day period' => ['from' => '2026-01-12', 'to' => '2026-01-12', 'cases' => [$open], 'contracts' => [self::contract('e1', 20)], 'patterns' => [$pattern], 'dates' => []],
		];

	}//end scenarios()

}//end class
