<?php

/**
 * Golden test for AvailabilityService::availability().
 *
 * Runs free hours over fixed working patterns, non-working times, calendar
 * dates, competences and agenda sources: shifts with and without planned
 * times, approved and pending leave, open and recovered sickness, timed
 * bookings and busy periods, an interview, secondments, entries past
 * midnight or outside the window, two entries on one day, a part-time
 * pattern, a required competence that expires mid-window, and duplicate
 * ids. Every answer is compared with tests/fixtures/golden/availability.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\AvailabilityService;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for free hours.
 *
 * @covers \OCA\Humaniq\Service\AvailabilityService
 * @uses   \OCA\Humaniq\Service\EmployeeWindow
 * @uses   \OCA\Humaniq\Service\CommitmentSpan
 * @uses   \OCA\Humaniq\Service\AgendaComposer
 * @uses   \OCA\Humaniq\Service\AgendaBookingEntries
 * @uses   \OCA\Humaniq\Service\AgendaSecondmentEntries
 * @uses   \OCA\Humaniq\Service\AgendaSubject
 * @uses   \OCA\Humaniq\Service\AgendaWindow
 * @uses   \OCA\Humaniq\Service\CompetenceCheckService
 * @uses   \OCA\Humaniq\Service\WorkingHoursService
 * @uses \OCA\Humaniq\Service\EmployeeWorkingTime
 * @uses \OCA\Humaniq\Service\WorkingDay
 * @uses \OCA\Humaniq\Service\WorkingTimeSources
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */
class AvailabilityGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/availability.json';

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
		$actual = json_encode($this->free(self::scenarios()[$case]), $flags);
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
	 * Run one scenario through the availability.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function free(array $s): array {
		return (new AvailabilityService())->availability(
			$s['employees'] ?? ['e1', 'e2'],
			new DateTimeImmutable($s['from'] ?? '2026-06-01'),
			new DateTimeImmutable($s['to'] ?? '2026-06-05'),
			$s['sources'],
			$s['required'] ?? []
		);

	}//end free()

	/**
	 * A working pattern of the same hours Monday to Friday.
	 *
	 * @param string $employeeId The employee.
	 * @param float $hours Hours per day.
	 *
	 * @return array<string, mixed>
	 */
	private static function pattern(string $employeeId, float $hours = 8.0): array {
		return ['employeeId' => $employeeId, 'validFrom' => '2026-01-01', 'hoursMonday' => $hours, 'hoursTuesday' => $hours, 'hoursWednesday' => $hours, 'hoursThursday' => $hours, 'hoursFriday' => $hours];

	}//end pattern()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$patterns = [self::pattern('e1'), self::pattern('e2', 4.8)];
		$base = ['workingPatterns' => $patterns, 'nonWorkingDates' => []];

		return [
			'nothing planned' => ['sources' => $base],
			'no pattern' => ['employees' => ['e1', 'e9'], 'sources' => $base],
			'duplicate ids' => ['employees' => ['e2', 'e1', 'e1', 'e2'], 'sources' => $base],
			'calendar and non-working time' => ['sources' => ($base + ['nonWorkingTimes' => [['employeeId' => 'e1', 'startDate' => '2026-06-03', 'endDate' => '2026-06-03', 'hoursNotWorked' => 8]]])],
			'unread calendar' => ['sources' => ['workingPatterns' => $patterns]],
			'calendar day off' => ['sources' => ['workingPatterns' => $patterns, 'nonWorkingDates' => ['2026-06-02']]],
			'leave' => ['sources' => ($base + ['leaveRequests' => [
				['id' => 'l1', 'employeeId' => 'e1', 'startDate' => '2026-06-02', 'endDate' => '2026-06-03', 'status' => 'approved'],
				['id' => 'l2', 'employeeId' => 'e2', 'startDate' => '2026-06-01', 'endDate' => '2026-06-05', 'status' => 'pending'],
				['id' => 'l3', 'employeeId' => 'e2', 'startDate' => '2026-05-28', 'endDate' => '2026-06-02', 'status' => 'approved'],
			]])],
			'sickness' => ['sources' => ($base + ['sickLeaveCases' => [
				['id' => 's1', 'employeeId' => 'e1', 'firstSickDay' => '2026-06-04', 'status' => 'gemeld'],
				['id' => 's2', 'employeeId' => 'e2', 'firstSickDay' => '2026-05-01', 'recoveredDate' => '2026-06-02', 'status' => 'hersteld'],
			]])],
			'shifts' => ['sources' => ($base + [
				'shifts' => [['id' => 'sh1', 'name' => 'Vroeg']],
				'assignments' => [
					['id' => 'a1', 'employeeId' => 'e1', 'shiftId' => 'sh1', 'date' => '2026-06-01', 'plannedStart' => '2026-06-01T07:00:00', 'plannedEnd' => '2026-06-01T11:30:00'],
					['id' => 'a2', 'employeeId' => 'e1', 'shiftId' => 'sh1', 'date' => '2026-06-02'],
					['id' => 'a3', 'employeeId' => 'e2', 'shiftId' => 'sh1', 'date' => '2026-06-03', 'plannedStart' => '2026-06-03T22:00:00', 'plannedEnd' => '2026-06-04T03:00:00'],
				],
			])],
			'bookings and busy time' => ['sources' => ($base + [
				'bookings' => [
					['id' => 'b1', 'employeeId' => 'e1', 'start' => '2026-06-01T09:00:00', 'end' => '2026-06-01T15:00:00'],
					['id' => 'b2', 'employeeId' => 'e1', 'start' => '2026-06-01T13:00:00', 'end' => '2026-06-01T17:00:00'],
					['id' => 'b3', 'employeeId' => 'e2', 'start' => '2026-06-06T09:00:00', 'end' => '2026-06-06T12:00:00'],
					['id' => 'b4', 'employeeId' => 'e2', 'start' => '2026-06-05T12:00:00', 'end' => '2026-06-05T11:00:00'],
				],
				'subscriptions' => [['id' => 'sub1', 'employeeId' => 'e2', 'busyPeriods' => [['start' => '2026-06-02T09:00:00', 'end' => '2026-06-02T10:30:00']]]],
			])],
			'interview' => ['sources' => ($base + ['interviews' => [['id' => 'i1', 'employeeId' => 'e1', 'candidateName' => 'X', 'scheduledStart' => '2026-06-03T10:00:00', 'scheduledEnd' => '2026-06-03T11:00:00', 'interviewers' => ['e1']]]])],
			'secondment' => ['sources' => ($base + ['secondments' => [
				['id' => 'sec1', 'employeeId' => 'e1', 'status' => 'actief', 'startDate' => '2026-06-02', 'endDate' => '', 'hoursPerWeek' => 16],
				['id' => 'sec2', 'employeeId' => 'e2', 'status' => 'actief', 'startDate' => '2026-01-01', 'endDate' => '2026-06-03', 'hoursPerWeek' => 40],
				['id' => 'sec3', 'employeeId' => 'e2', 'status' => 'concept', 'startDate' => '2026-01-01', 'hoursPerWeek' => 10],
			]])],
			'secondment without pattern' => ['employees' => ['e9'], 'sources' => ($base + ['secondments' => [['id' => 'sec1', 'employeeId' => 'e9', 'status' => 'actief', 'startDate' => '2026-06-01', 'hoursPerWeek' => 16]]])],
			'leave and shift on one day' => ['sources' => ($base + [
				'leaveRequests' => [['id' => 'l1', 'employeeId' => 'e1', 'startDate' => '2026-06-01', 'endDate' => '2026-06-01', 'status' => 'approved']],
				'assignments' => [['id' => 'a1', 'employeeId' => 'e1', 'date' => '2026-06-01', 'plannedStart' => '2026-06-01T08:00:00', 'plannedEnd' => '2026-06-01T12:00:00']],
				'bookings' => [['id' => 'b1', 'employeeId' => 'e2', 'start' => '2026-06-01T08:00:00', 'end' => '2026-06-01T10:00:00']],
			])],
			'leave on days off' => ['sources' => [
				'workingPatterns' => $patterns,
				'nonWorkingDates' => ['2026-06-02'],
				'nonWorkingTimes' => [['employeeId' => 'e1', 'startDate' => '2026-06-03', 'endDate' => '2026-06-03', 'hoursNotWorked' => 8]],
				'leaveRequests' => [['id' => 'l1', 'employeeId' => 'e1', 'startDate' => '2026-06-02', 'endDate' => '2026-06-04', 'status' => 'approved']],
			]],
			'secondment before the pattern starts' => ['employees' => ['e1'], 'to' => '2026-06-12', 'sources' => [
				'workingPatterns' => [['employeeId' => 'e1', 'validFrom' => '2026-06-08', 'hoursMonday' => 8, 'hoursTuesday' => 8, 'hoursWednesday' => 8, 'hoursThursday' => 8, 'hoursFriday' => 8]],
				'nonWorkingDates' => [],
				'secondments' => [['id' => 'sec1', 'employeeId' => 'e1', 'status' => 'actief', 'startDate' => '2026-06-01', 'hoursPerWeek' => 16]],
			]],
			'weekend entries' => ['from' => '2026-06-05', 'to' => '2026-06-08', 'sources' => ($base + ['bookings' => [['id' => 'b1', 'employeeId' => 'e1', 'start' => '2026-06-06T09:00:00', 'end' => '2026-06-08T10:00:00']]])],
			'required competence' => [
				'employees' => ['e1', 'e2', 'e3'],
				'required' => ['vca', 'bhv'],
				'sources' => ($base + ['workingPatterns' => [self::pattern('e1'), self::pattern('e2'), self::pattern('e3')], 'competences' => [
					['employeeId' => 'e1', 'competenceCode' => 'vca', 'issuedOn' => '2025-01-01'],
					['employeeId' => 'e1', 'competenceCode' => 'bhv', 'issuedOn' => '2025-01-01', 'validUntil' => '2027-01-01'],
					['employeeId' => 'e2', 'competenceCode' => 'vca'],
					['employeeId' => 'e2', 'competenceCode' => 'bhv', 'validUntil' => '2026-06-03'],
					['employeeId' => 'e3', 'competenceCode' => 'vca', 'issuedOn' => '2026-06-02'],
					['employeeId' => 'e3', 'competenceCode' => 'bhv'],
				]]),
			],
		];

	}//end scenarios()

}//end class
