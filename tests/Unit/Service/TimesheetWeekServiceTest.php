<?php

/**
 * TimesheetWeekService unit tests
 *
 * The week view composes booked entries with read-only leave and sickness
 * lines in hours from the working pattern, and totals per kind beside the
 * contract hours (time-non-project-activities D2, D3).
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AbsenceDays;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\TimeActivityCatalog;
use OCA\Humaniq\Service\TimesheetWeekService;
use OCA\Humaniq\Service\WorkingHoursService;
use PHPUnit\Framework\TestCase;

/**
 * Value-level assertions on the week view.
 */
class TimesheetWeekServiceTest extends TestCase {

	/**
	 * Rows the fake store returns, by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The stored timesheets, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $timesheets = [];

	/**
	 * The subject.
	 *
	 * @var TimesheetWeekService
	 */
	private TimesheetWeekService $service;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// A 36-hour week: eight hours Monday to Thursday, four on Friday.
		$this->rows = [
			'TimeActivity' => [
				['code' => 'project', 'kind' => 'project'],
				['code' => 'opleiding', 'kind' => 'non-project'],
				['code' => 'verlof', 'kind' => 'absence'],
			],
			'WorkingPattern' => [
				[
					'employeeId' => 'emp-1',
					'validFrom' => '2026-01-01',
					'hoursMonday' => 8,
					'hoursTuesday' => 8,
					'hoursWednesday' => 8,
					'hoursThursday' => 8,
					'hoursFriday' => 4,
					'hoursSaturday' => 0,
					'hoursSunday' => 0,
				],
			],
			'NonWorkingTime' => [],
			'LeaveRequest' => [],
			'SickLeaveCase' => [],
			'TimeEntry' => [],
		];
		$this->timesheets = [
			'ts-week' => ['id' => 'ts-week', 'employeeId' => 'emp-1', 'period' => '2026-W45'],
			'ts-month' => ['id' => 'ts-month', 'employeeId' => 'emp-1', 'period' => '2026-11'],
		];

		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findObjectData')->willReturnCallback(
			fn (string $uuid, string $schema): ?array => ($schema === 'Timesheet' ? ($this->timesheets[$uuid] ?? null) : null)
		);
		$store->method('loadAll')->willReturnCallback(fn (string $schema): array => ($this->rows[$schema] ?? []));
		$store->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters): array {
				return array_values(array_filter(
					($this->rows[$schema] ?? []),
					static function (array $row) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}
		);

		$this->service = new TimesheetWeekService(
			store: $store,
			activities: new TimeActivityCatalog(store: $store),
			absenceDays: new AbsenceDays(new WorkingHoursService(), new AbsenceProgression())
		);
	}//end setUp()

	/**
	 * Add a booked entry to the week timesheet.
	 *
	 * @param string               $date  The day.
	 * @param float                $hours The hours.
	 * @param array<string, mixed> $extra Further fields.
	 *
	 * @return void
	 */
	private function book(string $date, float $hours, array $extra = []): void {
		$this->rows['TimeEntry'][] = ['timesheetId' => 'ts-week', 'date' => $date, 'hours' => $hours] + $extra;
	}//end book()

	/**
	 * The spec's week: 26 project hours, 2 training hours and one approved
	 * leave day of 8 hours add up to the 36 contract hours.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function testAWeekThatAddsUp(): void {
		$this->book('2026-11-03', 8, ['projectId' => 'P-1']);
		$this->book('2026-11-04', 6, ['projectId' => 'P-1']);
		$this->book('2026-11-04', 2, ['activityCode' => 'opleiding']);
		$this->book('2026-11-05', 8, ['projectId' => 'P-2', 'activityCode' => 'project']);
		$this->book('2026-11-06', 4, ['projectId' => 'P-2']);
		$this->rows['LeaveRequest'][] = ['employeeId' => 'emp-1', 'status' => 'approved', 'startDate' => '2026-11-02', 'endDate' => '2026-11-02'];

		$view = $this->service->weekFor('ts-week');

		self::assertNotNull($view);
		self::assertSame('2026-11-02', $view['from']);
		self::assertSame('2026-11-08', $view['to']);
		self::assertSame(
			['projectHours' => 26.0, 'nonProjectHours' => 2.0, 'absenceHours' => 8.0, 'bookedHours' => 36.0, 'contractHours' => 36.0],
			$view['totals']
		);

		$absence = array_values(array_filter($view['lines'], static fn (array $line): bool => $line['readOnly'] === true));
		self::assertCount(1, $absence);
		self::assertSame('2026-11-02', $absence[0]['date']);
		self::assertSame('verlof', $absence[0]['activityCode']);
		self::assertSame('leave', $absence[0]['source']);
		self::assertSame(8.0, $absence[0]['hours']);
		self::assertSame('2026-11-02', $view['lines'][0]['date'], 'lines are in date order');
	}//end testAWeekThatAddsUp()

	/**
	 * Half-day sickness costs half of each contracted day from the first sick
	 * day on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function testAHalfDaySickness(): void {
		$this->rows['SickLeaveCase'][] = [
			'employeeId' => 'emp-1',
			'status' => 'gemeld',
			'firstSickDay' => '2026-11-05',
			'absenceProgression' => [['effectiveFrom' => '2026-11-05', 'absencePercentage' => 50]],
		];

		$view = $this->service->weekFor('ts-week');

		$sick = [];
		foreach ($view['lines'] as $line) {
			if ($line['source'] === 'sickness') {
				$sick[$line['date']] = $line['hours'];
			}
		}

		self::assertSame(['2026-11-05' => 4.0, '2026-11-06' => 2.0], $sick);
		self::assertSame(6.0, $view['totals']['absenceHours']);
	}//end testAHalfDaySickness()

	/**
	 * A week without leave or sickness has no absence lines and no absence hours.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function testAWeekWithoutAbsence(): void {
		$this->book('2026-11-03', 7.5);
		$this->rows['LeaveRequest'][] = ['employeeId' => 'emp-1', 'status' => 'submitted', 'startDate' => '2026-11-02', 'endDate' => '2026-11-02'];
		$this->rows['SickLeaveCase'][] = ['employeeId' => 'emp-1', 'status' => 'hersteld', 'firstSickDay' => '2026-10-01', 'recoveredDate' => '2026-10-05'];

		$view = $this->service->weekFor('ts-week');

		self::assertCount(1, $view['lines']);
		self::assertSame('entry', $view['lines'][0]['source']);
		self::assertSame('non-project', $view['lines'][0]['kind'], 'an entry with neither activity nor project counts as non-project');
		self::assertSame(0.0, $view['totals']['absenceHours']);
		self::assertSame(7.5, $view['totals']['nonProjectHours']);
	}//end testAWeekWithoutAbsence()

	/**
	 * A leave request with its own hours spreads them over its working days in
	 * proportion to each day's contracted hours.
	 *
	 * @return void
	 */
	public function testLeaveHoursAreSpreadOverTheWorkingDays(): void {
		$this->rows['LeaveRequest'][] = ['employeeId' => 'emp-1', 'status' => 'approved', 'startDate' => '2026-11-05', 'endDate' => '2026-11-08', 'hours' => 6];

		$view = $this->service->weekFor('ts-week');

		$leave = [];
		foreach ($view['lines'] as $line) {
			$leave[$line['date']] = $line['hours'];
		}

		// Thursday 8 and Friday 4 contracted, so 6 hours split 4 and 2; the weekend costs nothing.
		self::assertSame(['2026-11-05' => 4.0, '2026-11-06' => 2.0], $leave);
	}//end testLeaveHoursAreSpreadOverTheWorkingDays()

	/**
	 * Sickness on a leave day takes only what the leave left of the day.
	 *
	 * @return void
	 */
	public function testADayNeverCarriesMoreAbsenceThanItsContractedHours(): void {
		$this->rows['LeaveRequest'][] = ['employeeId' => 'emp-1', 'status' => 'approved', 'startDate' => '2026-11-02', 'endDate' => '2026-11-02'];
		$this->rows['SickLeaveCase'][] = ['employeeId' => 'emp-1', 'status' => 'hersteld', 'firstSickDay' => '2026-11-02', 'recoveredDate' => '2026-11-03'];

		$view = $this->service->weekFor('ts-week');

		$bySource = [];
		foreach ($view['lines'] as $line) {
			$bySource[] = [$line['date'], $line['source'], $line['hours']];
		}

		self::assertSame([['2026-11-02', 'leave', 8.0], ['2026-11-03', 'sickness', 8.0]], $bySource, 'a recovered case ends on its recovered date');
		self::assertSame(16.0, $view['totals']['absenceHours']);
	}//end testADayNeverCarriesMoreAbsenceThanItsContractedHours()

	/**
	 * A month timesheet has one totals row per ISO week it touches.
	 *
	 * @return void
	 */
	public function testAMonthIsSplitIntoItsWeeks(): void {
		$this->rows['TimeEntry'][] = ['timesheetId' => 'ts-month', 'date' => '2026-11-03', 'hours' => 5, 'projectId' => 'P-1'];

		$view = $this->service->weekFor('ts-month');

		self::assertSame('2026-11-01', $view['from']);
		self::assertSame('2026-11-30', $view['to']);
		self::assertSame(['2026-W44', '2026-W45', '2026-W46', '2026-W47', '2026-W48', '2026-W49'], array_column($view['weeks'], 'week'));
		self::assertSame(0.0, $view['weeks'][0]['contractHours'], 'Sunday 1 November is not a working day');
		self::assertSame(5.0, $view['weeks'][1]['projectHours']);
		self::assertSame(36.0, $view['weeks'][1]['contractHours']);
		self::assertSame(8.0, $view['weeks'][5]['contractHours'], 'Monday 30 November alone');
	}//end testAMonthIsSplitIntoItsWeeks()

	/**
	 * The period grains a timesheet accepts, and none other.
	 *
	 * @return void
	 */
	public function testPeriodRange(): void {
		$month = TimesheetWeekService::periodRange('2026-02');
		self::assertSame(['2026-02-01', '2026-02-28'], [$month[0]->format('Y-m-d'), $month[1]->format('Y-m-d')]);

		$week = TimesheetWeekService::periodRange('2026-W01');
		self::assertSame(['2025-12-29', '2026-01-04'], [$week[0]->format('Y-m-d'), $week[1]->format('Y-m-d')]);

		$day = TimesheetWeekService::periodRange('2026-W45-3');
		self::assertSame(['2026-11-04', '2026-11-04'], [$day[0]->format('Y-m-d'), $day[1]->format('Y-m-d')]);

		self::assertNull(TimesheetWeekService::periodRange('2026-13'));
		self::assertNull(TimesheetWeekService::periodRange('2026-W54'));
		self::assertNull(TimesheetWeekService::periodRange('november'));
	}//end testPeriodRange()

	/**
	 * A timesheet that is gone has no view.
	 *
	 * @return void
	 */
	public function testAMissingTimesheetHasNoView(): void {
		self::assertNull($this->service->weekFor('ts-gone'));
		self::assertNull($this->service->weekFor('  '));
	}//end testAMissingTimesheetHasNoView()

	/**
	 * The absence total the aggregate recompute reads.
	 *
	 * @return void
	 */
	public function testAbsenceHoursFor(): void {
		$this->rows['LeaveRequest'][] = ['employeeId' => 'emp-1', 'status' => 'approved', 'startDate' => '2026-11-05', 'endDate' => '2026-11-06'];

		self::assertSame(12.0, $this->service->absenceHoursFor($this->timesheets['ts-week'], []));
	}//end testAbsenceHoursFor()

}//end class
