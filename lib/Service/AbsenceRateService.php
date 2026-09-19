<?php

/**
 * Absence Rate Service
 *
 * Computes the verzuimpercentage over a period from `SickLeaveCase`
 * absence progressions and `EmploymentContract` availability.
 *
 * WHY THIS EXISTS
 * ---------------
 * `verzuim-analytics-widgets` shipped four absence STAT widgets and named
 * frequency/duration trends a non-goal, for a reason that was correct at the
 * time: `SickLeaveCase.status` was `gemeld` or `hersteld` and nothing between,
 * so an absence could only be counted as whole calendar days. That overstates
 * every case where the employee is partly back at work -- which, under the Wet
 * verbetering poortwachter, is most of the long ones, because partial
 * resumption is the entire point of the re-integration duty. A dashboard tile
 * labelled "verzuimpercentage" fed by whole-day counting is not a conservative
 * approximation; it is a different number wearing the name of the one HR
 * reports to the sector.
 *
 * `SickLeaveCase.absenceProgression` supplies the missing dimension and this
 * service turns it into a rate.
 *
 * THE DEFINITION IMPLEMENTED
 * --------------------------
 * The standard employer verzuimpercentage, in FTE-weighted calendar-day
 * equivalents:
 *
 *     percentage = absent day-equivalents / available day-equivalents * 100
 *
 * where a day counts for the fraction of contracted hours actually missed
 * (a 40%-absent day is 0.4 of a day) and every day is weighted by the
 * employee's FTE, so a half-time employee's absence weighs half of a
 * full-timer's. This is the definition CBS and the sector benchmarks use for
 * the employer-side figure. It is deliberately NOT a headcount ratio and NOT a
 * count of open cases -- both of those already exist as stat widgets and
 * neither is steerable.
 *
 * WHAT IT REFUSES TO DO
 * ---------------------
 * Two refusals matter more than the arithmetic:
 *
 * 1. **An absence with no employment contract covering it is not measured.**
 *    It contributes to neither numerator nor denominator, and the count of
 *    such cases is returned so the caller can surface it. Weighting an
 *    uncontracted absence at a guessed 1.0 FTE would invent the very number
 *    the field was added to stop inventing.
 * 2. **Zero availability yields `null`, never `0.0`.** A period with no
 *    contracted employees has no absence rate. Returning 0.0 would render on
 *    a dashboard as "0% -- excellent", which is a measurement that never ran
 *    reported as a good result.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/absence-rate/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Pure calculator turning sick-leave cases plus employment contracts into an
 * FTE-weighted verzuimpercentage for a period.
 *
 * Deliberately dependency-free: no container, no ObjectService, no clock. The
 * caller supplies the already-fetched arrays and the period, which is what
 * makes every branch below reachable from a unit test without a Nextcloud
 * bootstrap.
 *
 * @spec openspec/specs/absence-rate/spec.md
 */
class AbsenceRateService {

	/**
	 * Contracted hours a 1.0 FTE works per week, when the caller does not
	 * supply one.
	 *
	 * 40 is the hours-per-week a full-time NL contract is most commonly
	 * expressed in, but it is a CAO-dependent figure (36 in much of the
	 * public sector, 38 in parts of care) -- which is why it is a default
	 * argument rather than a constant baked into the arithmetic. A caller
	 * that knows the CAO SHOULD pass its own.
	 *
	 * @var float
	 */
	public const DEFAULT_FULL_TIME_HOURS_PER_WEEK = 40.0;

	/**
	 * @param AbsenceProgression $progression The step-function half of the calculation.
	 * @param WorkingHoursService $workingHours The one definition of a working day (working-hours-per-person, design D6).
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	public function __construct(
		private readonly AbsenceProgression $progression = new AbsenceProgression(),
		private readonly WorkingHoursService $workingHours = new WorkingHoursService(),
	) {

	}//end __construct()

	/**
	 * Compute the verzuimpercentage over a closed period.
	 *
	 * @param array<array<string, mixed>> $cases SickLeaveCase objects (plain arrays), any status.
	 * @param array<array<string, mixed>> $contracts EmploymentContract objects (plain arrays).
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 * @param array<array<string, mixed>> $workingPatterns WorkingPattern objects, when the caller has them.
	 * @param array<array<string, mixed>> $nonWorkingTimes NonWorkingTime objects, when the caller has them.
	 * @param array<int, string>|null $nonWorkingDates ISO dates openregister's working calendar marks non-working, or null when it could not be read.
	 *
	 * @return array{absentDayEquivalents: float, availableDayEquivalents: float, percentage: float|null, casesWithoutContract: int, employeesMeasuredOnPattern: int}
	 *                                                                                                                                                                `percentage` is null when availability is zero -- see the class docblock.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-001
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function absenceRate(
		array $cases,
		array $contracts,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
		float $fullTimeHoursWeek = self::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
		array $workingPatterns = [],
		array $nonWorkingTimes = [],
		?array $nonWorkingDates = null,
	): array {
		$ftePerEmployee = $this->fteByEmployee(
			contracts: $contracts,
			periodStart: $periodStart,
			periodEnd: $periodEnd,
			fullTimeHoursWeek: $fullTimeHoursWeek
		);

		// Availability comes from the working pattern wherever the employee has
		// one, so an absence percentage and a capacity percentage divide by the
		// same definition of a working day (working-hours-per-person, D6). An
		// employee with no pattern keeps the contract-and-fte path, which is
		// what every instance had before patterns existed.
		$onPattern = $this->employeesOnPattern(
			workingPatterns: $workingPatterns,
			ftePerEmployee: $ftePerEmployee
		);

		$available = ($this->contractAvailabilityOutsidePattern(
			contracts: $contracts,
			onPattern: $onPattern,
			periodStart: $periodStart,
			periodEnd: $periodEnd,
			fullTimeHoursWeek: $fullTimeHoursWeek
		) + $this->patternAvailability(
			onPattern: $onPattern,
			periodStart: $periodStart,
			periodEnd: $periodEnd,
			fullTimeHoursWeek: $fullTimeHoursWeek,
			workingPatterns: $workingPatterns,
			nonWorkingTimes: $nonWorkingTimes,
			nonWorkingDates: $nonWorkingDates
		));

		$measured = $this->absentDayEquivalents(
			cases: $cases,
			ftePerEmployee: $ftePerEmployee,
			periodStart: $periodStart,
			periodEnd: $periodEnd
		);

		$absent = $measured['absent'];
		$unmeasured = $measured['unmeasured'];

		// Refusal 2: no availability means no rate, not a rate of zero.
		$percentage = null;
		if ($available > 0.0) {
			$percentage = round(num: (($absent / $available) * 100.0), precision: 2);
		}

		return [
			'absentDayEquivalents' => round(num: $absent, precision: 4),
			'availableDayEquivalents' => round(num: $available, precision: 4),
			'percentage' => $percentage,
			'casesWithoutContract' => $unmeasured,
			'employeesMeasuredOnPattern' => count($onPattern),
		];
	}//end absenceRate()

	/**
	 * The employees whose availability comes from a working pattern.
	 *
	 * An employee is only on the pattern path when a contract also covers the
	 * period, so a pattern for somebody nobody employs cannot add availability.
	 *
	 * @param array<array<string, mixed>> $workingPatterns WorkingPattern objects.
	 * @param array<string, float> $ftePerEmployee FTE over the period, per employee.
	 *
	 * @return array<string, bool> The employee ids, keyed.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	private function employeesOnPattern(array $workingPatterns, array $ftePerEmployee): array {
		$onPattern = [];
		foreach ($workingPatterns as $pattern) {
			$patternEmployee = $this->stringOrNull(value: ($pattern['employeeId'] ?? null));
			if ($patternEmployee !== null && isset($ftePerEmployee[$patternEmployee]) === true) {
				$onPattern[$patternEmployee] = true;
			}
		}

		return $onPattern;
	}//end employeesOnPattern()

	/**
	 * Availability of the employees who have no working pattern.
	 *
	 * This is the contract-and-fte path every instance used before patterns
	 * existed, and it stays the answer for anybody still without one.
	 *
	 * @param array<array<string, mixed>> $contracts EmploymentContract objects.
	 * @param array<string, bool> $onPattern The employees the pattern path owns.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 *
	 * @return float Available day-equivalents.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-001
	 */
	private function contractAvailabilityOutsidePattern(
		array $contracts,
		array $onPattern,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
		float $fullTimeHoursWeek
	): float {
		$available = 0.0;
		foreach ($contracts as $contract) {
			$contractEmployee = $this->stringOrNull(value: ($contract['employeeId'] ?? null));
			if ($contractEmployee !== null && isset($onPattern[$contractEmployee]) === true) {
				continue;
			}

			$available += $this->contractAvailability(
				contract: $contract,
				periodStart: $periodStart,
				periodEnd: $periodEnd,
				fullTimeHoursWeek: $fullTimeHoursWeek
			);
		}

		return $available;
	}//end contractAvailabilityOutsidePattern()

	/**
	 * Availability of the employees whose hours come from a working pattern.
	 *
	 * @param array<string, bool> $onPattern The employees the pattern path owns.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 * @param array<array<string, mixed>> $workingPatterns WorkingPattern objects.
	 * @param array<array<string, mixed>> $nonWorkingTimes NonWorkingTime objects.
	 * @param array<int, string>|null $nonWorkingDates ISO dates the working calendar marks non-working, or null when unread.
	 *
	 * @return float Available day-equivalents.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	private function patternAvailability(
		array $onPattern,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
		float $fullTimeHoursWeek,
		array $workingPatterns,
		array $nonWorkingTimes,
		?array $nonWorkingDates
	): float {
		$available = 0.0;
		foreach (array_keys($onPattern) as $patternEmployee) {
			$resolved = $this->workingHours->contractedHoursOver(
				employeeId: (string)$patternEmployee,
				from: $periodStart,
				to: $periodEnd,
				patterns: $workingPatterns,
				nonWorkingTimes: $nonWorkingTimes,
				nonWorkingDates: $nonWorkingDates
			);

			$available += $this->workingHours->dayEquivalents(
				hours: $resolved['hours'],
				fullTimeHoursWeek: $fullTimeHoursWeek
			);
		}

		return $available;
	}//end patternAvailability()

	/**
	 * Absent day-equivalents over the period, and the cases nothing measured.
	 *
	 * Refusal 1: an absence with no contract covering the period is not
	 * measured, and is counted so the caller can say so.
	 *
	 * @param array<array<string, mixed>> $cases SickLeaveCase objects.
	 * @param array<string, float> $ftePerEmployee FTE over the period, per employee.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 *
	 * @return array{absent: float, unmeasured: int} The total and the count of unmeasured cases.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function absentDayEquivalents(
		array $cases,
		array $ftePerEmployee,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd
	): array {
		$absent = 0.0;
		$unmeasured = 0;
		foreach ($cases as $case) {
			$employeeId = $this->stringOrNull(value: ($case['employeeId'] ?? null));
			if ($employeeId === null || isset($ftePerEmployee[$employeeId]) === false) {
				++$unmeasured;
				continue;
			}

			$absent += $this->caseAbsenceDayEquivalents(
				case: $case,
				periodStart: $periodStart,
				periodEnd: $periodEnd,
				fte: $ftePerEmployee[$employeeId]
			);
		}

		return [
			'absent' => $absent,
			'unmeasured' => $unmeasured,
		];
	}//end absentDayEquivalents()

	/**
	 * Absent day-equivalents one case contributes to one period.
	 *
	 * Walks `absenceProgression` as a step function: each step holds its
	 * percentage until the next step's `effectiveFrom`, and the whole series is
	 * clipped to the intersection of the period and the case window
	 * (`firstSickDay` .. `recoveredDate`, or the period end while the case is
	 * still open).
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 * @param float $fte The employee's FTE over the period.
	 *
	 * @return float Day-equivalents, already FTE-weighted.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	public function caseAbsenceDayEquivalents(
		array $case,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
		float $fte,
	): float {
		$firstSickDay = $this->progression->date(value: ($case['firstSickDay'] ?? null));
		if ($firstSickDay === null) {
			// firstSickDay is a required property; a case missing it is
			// unmeasurable rather than zero-length, but there is no channel to
			// report it from here, so the caller's own validation owns that.
			return 0.0;
		}

		$window = $this->caseWindow(
			case: $case,
			firstSickDay: $firstSickDay,
			periodStart: $periodStart,
			periodEnd: $periodEnd
		);
		if ($window === null) {
			return 0.0;
		}

		return $this->progression->sum(
			steps: $this->progression->steps(case: $case, firstSickDay: $firstSickDay),
			windowStart: $window[0],
			windowEnd: $window[1],
			fte: $fte
		);
	}//end caseAbsenceDayEquivalents()

	/**
	 * The intersection of one case's own window with the reporting period.
	 *
	 * The case window closes on `recoveredDate` ONLY when the case actually
	 * recovered. A reopened case (`heropenen` clears `recoveredDate`) is open
	 * again, and a stale `recoveredDate` left on a `gemeld` case must not
	 * truncate it -- the lifecycle status is the authority, not the date field.
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 * @param DateTimeImmutable $firstSickDay The case anchor date.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null Clipped window, or null when the case does not overlap the period at all.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function caseWindow(
		array $case,
		DateTimeImmutable $firstSickDay,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
	): ?array {
		$caseEnd = $periodEnd;
		$recovered = $this->progression->date(value: ($case['recoveredDate'] ?? null));
		if ($recovered !== null && ($case['status'] ?? null) === 'hersteld') {
			$caseEnd = $recovered;
		}

		$windowStart = ($firstSickDay > $periodStart) ? $firstSickDay : $periodStart;
		$windowEnd = ($caseEnd < $periodEnd) ? $caseEnd : $periodEnd;
		if ($windowStart > $windowEnd) {
			return null;
		}

		return [$windowStart, $windowEnd];
	}//end caseWindow()

	/**
	 * Available day-equivalents one contract contributes to a period.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 *
	 * @return float Day-equivalents.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function contractAvailability(
		array $contract,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
		float $fullTimeHoursWeek,
	): float {
		$fte = $this->contractFte(contract: $contract, fullTimeHoursWeek: $fullTimeHoursWeek);
		if ($fte <= 0.0) {
			return 0.0;
		}

		$start = ($this->progression->date(value: ($contract['startDate'] ?? null)) ?? $periodStart);
		$end = ($this->progression->date(value: ($contract['endDate'] ?? null)) ?? $periodEnd);

		$from = ($start > $periodStart) ? $start : $periodStart;
		$to = ($end < $periodEnd) ? $end : $periodEnd;
		if ($from > $to) {
			return 0.0;
		}

		return ($this->progression->inclusiveDays(from: $from, to: $to) * $fte);
	}//end contractAvailability()

	/**
	 * Map each employee to the FTE their contracts give them over the period.
	 *
	 * An employee holding two overlapping contracts sums to their combined FTE,
	 * which is also how the denominator counts them -- so numerator and
	 * denominator stay on the same basis even where the contract data is odd.
	 *
	 * @param array<array<string, mixed>> $contracts The EmploymentContracts.
	 * @param DateTimeImmutable $periodStart First day of the period, inclusive.
	 * @param DateTimeImmutable $periodEnd Last day of the period, inclusive.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 *
	 * @return array<string, float> Employee id to FTE, only for employees with a contract overlapping the period.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function fteByEmployee(
		array $contracts,
		DateTimeImmutable $periodStart,
		DateTimeImmutable $periodEnd,
		float $fullTimeHoursWeek,
	): array {
		$out = [];
		foreach ($contracts as $contract) {
			$employeeId = $this->stringOrNull(value: ($contract['employeeId'] ?? null));
			if ($employeeId === null) {
				continue;
			}

			$fte = $this->contractFte(contract: $contract, fullTimeHoursWeek: $fullTimeHoursWeek);
			if ($fte <= 0.0) {
				continue;
			}

			$start = ($this->progression->date(value: ($contract['startDate'] ?? null)) ?? $periodStart);
			$end = ($this->progression->date(value: ($contract['endDate'] ?? null)) ?? $periodEnd);
			if ($start > $periodEnd || $end < $periodStart) {
				continue;
			}

			$out[$employeeId] = (($out[$employeeId] ?? 0.0) + $fte);
		}

		return $out;
	}//end fteByEmployee()

	/**
	 * The FTE a contract represents.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 *
	 * @return float FTE, or 0.0 when hoursPerWeek is absent or unusable.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function contractFte(array $contract, float $fullTimeHoursWeek): float {
		$hours = ($contract['hoursPerWeek'] ?? null);
		if (is_numeric($hours) === false || $fullTimeHoursWeek <= 0.0) {
			return 0.0;
		}

		return max(0.0, ((float)$hours / $fullTimeHoursWeek));
	}//end contractFte()

	/**
	 * Narrow a raw value to a non-empty string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null Null when absent, not a string, or blank.
	 *
	 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-002
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
