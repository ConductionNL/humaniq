<?php

/**
 * AbsenceRateService tests
 *
 * Pins the arithmetic that makes a verzuimpercentage different from a
 * calendar-day count -- partial resumption, FTE weighting, period clipping --
 * and the two refusals that keep an unmeasured figure from rendering as a good
 * one: an absence with no contract is excluded and counted, and zero
 * availability yields null rather than 0.0.
 *
 * The whole-day control comes first, deliberately. Without it, a partial-day
 * assertion cannot distinguish "the progression was applied" from "the
 * progression was ignored and the number happened to match".
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\AbsenceRateService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbsenceRateService.
 */
class AbsenceRateServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AbsenceRateService
	 */
	private AbsenceRateService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new AbsenceRateService();
	}//end setUp()

	/**
	 * January 2026, the period every test measures over (31 days).
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Start and end.
	 */
	private function january(): array {
		return [
			new DateTimeImmutable('2026-01-01'),
			new DateTimeImmutable('2026-01-31'),
		];
	}//end january()

	/**
	 * A full-time contract running the whole period.
	 *
	 * @param string $employeeId The employee.
	 * @param float $hoursPerWeek Contracted hours.
	 *
	 * @return array<string, mixed> The contract.
	 */
	private function contract(string $employeeId, float $hoursPerWeek = 40.0): array {
		return [
			'employeeId' => $employeeId,
			'hoursPerWeek' => $hoursPerWeek,
			'startDate' => '2025-01-01',
			'endDate' => null,
		];
	}//end contract()

	/**
	 * THE CONTROL. One full-time employee, absent every day of the month with
	 * no progression recorded, is 100% absent -- the pre-existing whole-day
	 * behaviour, unchanged by the new field.
	 *
	 * Without this passing, none of the partial assertions below prove that
	 * `absenceProgression` was read at all.
	 *
	 * @return void
	 */
	public function testFullMonthAbsenceWithNoProgressionIsOneHundredPercent(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2025-12-01',
					'status' => 'gemeld',
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(31.0, $result['absentDayEquivalents']);
		$this->assertSame(31.0, $result['availableDayEquivalents']);
		$this->assertSame(100.0, $result['percentage']);
		$this->assertSame(0, $result['casesWithoutContract']);
	}//end testFullMonthAbsenceWithNoProgressionIsOneHundredPercent()

	/**
	 * A resumption halfway through the month is counted as partial absence,
	 * not as a whole-day absence and not as a recovery.
	 *
	 * 15 days fully absent (Jan 1-15) + 16 days at 40% (Jan 16-31)
	 * = 15 + 6.4 = 21.4 of 31 day-equivalents = 69.03%.
	 *
	 * This is the number the old whole-day counting got wrong: it would have
	 * reported 100%.
	 *
	 * @return void
	 */
	public function testPartialResumptionReducesTheRateBelowWholeDayCounting(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2025-12-01',
					'status' => 'gemeld',
					'absenceProgression' => [
						['effectiveFrom' => '2025-12-01', 'absencePercentage' => 100],
						['effectiveFrom' => '2026-01-16', 'absencePercentage' => 40],
					],
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(21.4, $result['absentDayEquivalents']);
		$this->assertSame(69.03, $result['percentage']);
	}//end testPartialResumptionReducesTheRateBelowWholeDayCounting()

	/**
	 * A progression whose first step starts after firstSickDay leaves the
	 * opening stretch undescribed; that stretch is full absence, not zero.
	 *
	 * Dropping it would understate every case where HR only records the
	 * resumption and never the original 100%.
	 *
	 * @return void
	 */
	public function testStretchBeforeTheFirstStepCountsAsFullAbsence(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2026-01-01',
					'status' => 'gemeld',
					'absenceProgression' => [
						['effectiveFrom' => '2026-01-21', 'absencePercentage' => 50],
					],
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		// Jan 1-20 at 100% = 20, Jan 21-31 at 50% = 5.5.
		$this->assertSame(25.5, $result['absentDayEquivalents']);
	}//end testStretchBeforeTheFirstStepCountsAsFullAbsence()

	/**
	 * A half-time employee's absence weighs half a full-timer's, on both sides
	 * of the ratio.
	 *
	 * Two employees, one 40h and one 20h, the 20h one absent all month:
	 * absent 15.5, available 31 + 15.5 = 46.5, rate 33.33%.
	 *
	 * A headcount ratio would have said 50%.
	 *
	 * @return void
	 */
	public function testAbsenceIsWeightedByFte(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-part',
					'firstSickDay' => '2025-11-01',
					'status' => 'gemeld',
				],
			],
			contracts: [
				$this->contract('emp-full', 40.0),
				$this->contract('emp-part', 20.0),
			],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(15.5, $result['absentDayEquivalents']);
		$this->assertSame(46.5, $result['availableDayEquivalents']);
		$this->assertSame(33.33, $result['percentage']);
	}//end testAbsenceIsWeightedByFte()

	/**
	 * A recovered case stops counting on its recoveredDate, not at period end.
	 *
	 * @return void
	 */
	public function testRecoveredCaseStopsCountingOnRecoveredDate(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2026-01-05',
					'recoveredDate' => '2026-01-14',
					'status' => 'hersteld',
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		// Jan 5 through Jan 14 inclusive = 10 days.
		$this->assertSame(10.0, $result['absentDayEquivalents']);
	}//end testRecoveredCaseStopsCountingOnRecoveredDate()

	/**
	 * A reopened case (heropenen) is open again: a recoveredDate left behind on
	 * a `gemeld` case must not truncate the window. The lifecycle status is the
	 * authority, not the date field.
	 *
	 * @return void
	 */
	public function testStaleRecoveredDateOnAnOpenCaseDoesNotTruncateIt(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2026-01-01',
					'recoveredDate' => '2026-01-10',
					'status' => 'gemeld',
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(31.0, $result['absentDayEquivalents']);
	}//end testStaleRecoveredDateOnAnOpenCaseDoesNotTruncateIt()

	/**
	 * A case wholly outside the period contributes nothing.
	 *
	 * @return void
	 */
	public function testCaseOutsideThePeriodContributesNothing(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2025-03-01',
					'recoveredDate' => '2025-03-20',
					'status' => 'hersteld',
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(0.0, $result['absentDayEquivalents']);
		$this->assertSame(0.0, $result['percentage']);
	}//end testCaseOutsideThePeriodContributesNothing()

	/**
	 * REFUSAL 1. An absence with no employment contract covering the period is
	 * excluded from both sides of the ratio and reported as unmeasured.
	 *
	 * Weighting it at a guessed 1.0 FTE would invent exactly the number the
	 * progression field exists to stop inventing.
	 *
	 * @return void
	 */
	public function testAbsenceWithoutAContractIsExcludedAndCounted(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-ghost',
					'firstSickDay' => '2026-01-01',
					'status' => 'gemeld',
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(0.0, $result['absentDayEquivalents']);
		$this->assertSame(31.0, $result['availableDayEquivalents']);
		$this->assertSame(0.0, $result['percentage']);
		$this->assertSame(1, $result['casesWithoutContract']);
	}//end testAbsenceWithoutAContractIsExcludedAndCounted()

	/**
	 * REFUSAL 2. No availability yields null, never 0.0.
	 *
	 * A period with no contracted employees has no absence rate. 0.0 would
	 * render on a dashboard as "0% -- excellent", which is a measurement that
	 * never ran reported as a good result.
	 *
	 * @return void
	 */
	public function testZeroAvailabilityYieldsNullNotZero(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [],
			contracts: [],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertNull($result['percentage']);
		$this->assertSame(0.0, $result['availableDayEquivalents']);
	}//end testZeroAvailabilityYieldsNullNotZero()

	/**
	 * A contract starting mid-period contributes only the days it covers.
	 *
	 * @return void
	 */
	public function testContractStartingMidPeriodProratesAvailability(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [],
			contracts: [
				[
					'employeeId' => 'emp-new',
					'hoursPerWeek' => 40.0,
					'startDate' => '2026-01-17',
					'endDate' => null,
				],
			],
			periodStart: $start,
			periodEnd: $end
		);

		// Jan 17 through Jan 31 inclusive = 15 days.
		$this->assertSame(15.0, $result['availableDayEquivalents']);
	}//end testContractStartingMidPeriodProratesAvailability()

	/**
	 * A non-40-hour full-time week (36 in much of the public sector) is honoured
	 * rather than silently treated as 0.9 FTE.
	 *
	 * @return void
	 */
	public function testFullTimeWeekIsConfigurable(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2025-11-01',
					'status' => 'gemeld',
				],
			],
			contracts: [$this->contract('emp-1', 36.0)],
			periodStart: $start,
			periodEnd: $end,
			fullTimeHoursWeek: 36.0
		);

		$this->assertSame(31.0, $result['absentDayEquivalents']);
		$this->assertSame(100.0, $result['percentage']);
	}//end testFullTimeWeekIsConfigurable()

	/**
	 * Malformed progression entries are skipped, and a progression that is
	 * entirely malformed falls back to full absence rather than to zero.
	 *
	 * Falling back to zero would turn a data-entry error into a clean absence
	 * record, which is the worst of the available failure modes.
	 *
	 * @return void
	 */
	public function testEntirelyMalformedProgressionFallsBackToFullAbsence(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2026-01-01',
					'status' => 'gemeld',
					'absenceProgression' => [
						['effectiveFrom' => 'not-a-date', 'absencePercentage' => 50],
						['absencePercentage' => 20],
						'garbage',
					],
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(31.0, $result['absentDayEquivalents']);
	}//end testEntirelyMalformedProgressionFallsBackToFullAbsence()

	/**
	 * Progression steps supplied out of order are sorted before being walked.
	 *
	 * @return void
	 */
	public function testOutOfOrderStepsAreSorted(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2026-01-01',
					'status' => 'gemeld',
					'absenceProgression' => [
						['effectiveFrom' => '2026-01-21', 'absencePercentage' => 25],
						['effectiveFrom' => '2026-01-01', 'absencePercentage' => 100],
						['effectiveFrom' => '2026-01-11', 'absencePercentage' => 50],
					],
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		// Jan 1-10 at 100% = 10, Jan 11-20 at 50% = 5, Jan 21-31 at 25% = 2.75.
		$this->assertSame(17.75, $result['absentDayEquivalents']);
	}//end testOutOfOrderStepsAreSorted()

	/**
	 * An absencePercentage of 0 records a full resumption while the case stays
	 * administratively open -- it contributes nothing, and does not close the
	 * case behind HR's back.
	 *
	 * @return void
	 */
	public function testZeroPercentStepContributesNothingWithoutClosingTheCase(): void {
		[$start, $end] = $this->january();

		$result = $this->service->absenceRate(
			cases: [
				[
					'employeeId' => 'emp-1',
					'firstSickDay' => '2026-01-01',
					'status' => 'gemeld',
					'absenceProgression' => [
						['effectiveFrom' => '2026-01-01', 'absencePercentage' => 100],
						['effectiveFrom' => '2026-01-11', 'absencePercentage' => 0],
					],
				],
			],
			contracts: [$this->contract('emp-1')],
			periodStart: $start,
			periodEnd: $end
		);

		$this->assertSame(10.0, $result['absentDayEquivalents']);
	}//end testZeroPercentStepContributesNothingWithoutClosingTheCase()
}//end class
