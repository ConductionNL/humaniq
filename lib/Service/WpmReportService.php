<?php

/**
 * WPM Report Service
 *
 * Compiles the yearly werkgebonden personenmobiliteit (WPM) kilometres for
 * one administration (expenses-travel-calculation D5): business kilometres
 * from approved and paid travel claims, commuting kilometres from approved
 * commuting arrangements under the 214-day rule pro rata for the months
 * they were active, both per transport mode and fuel type; the number of
 * people employed in the year against the 100-employee threshold; and the
 * trips that name no transport mode, listed rather than counted under a
 * guessed one. The result is kept as one `WpmReport` per administration and
 * year, updated on every compile.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The yearly mobility figures of one administration.
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
 */
class WpmReportService {

	/**
	 * The headcount from which the reporting duty applies.
	 *
	 * @var int
	 */
	public const THRESHOLD = 100;

	/**
	 * Claim states whose kilometres count.
	 *
	 * @var list<string>
	 */
	private const COUNTED_CLAIMS = ['approved', 'reimbursed'];

	/**
	 * Arrangement states whose kilometres count.
	 *
	 * @var list<string>
	 */
	private const COUNTED_ARRANGEMENTS = ['approved', 'ended'];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface      $gateway    Reads and writes the register.
	 * @param TravelAllowanceCalculator $calculator The 214-day arithmetic.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly TravelAllowanceCalculator $calculator,
	) {

	}//end __construct()

	/**
	 * Compile and keep the report for an administration and a year.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The calendar year.
	 * @param string $userId           Who compiles it.
	 *
	 * @return array<string, mixed> The report as saved, with its id.
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
	 */
	public function compile(string $administrationId, int $year, string $userId): array {
		$run = new WpmReportRun();
		$run->administrationId = $administrationId;
		$run->year = $year;
		$run->userId = $userId;
		$run->business = $this->businessKm(run: $run);
		$run->commute = $this->commuteKm(run: $run);
		$run->employees = $this->employeeCount($administrationId, $year);
		$report = $this->report(run: $run);

		$uuid = $this->storedReportId(run: $run);
		$saved = $this->gateway->save(payload: $report, schema: 'WpmReport', uuid: $uuid);

		return array_merge($report, ['id' => (string)$saved->getUuid()]);
	}//end compile()

	/**
	 * The report as it is saved.
	 *
	 * @param WpmReportRun $run The compiled figures.
	 *
	 * @return array<string, mixed>
	 */
	private function report(WpmReportRun $run): array {
		return [
			'administrationId' => $run->administrationId,
			'year' => $run->year,
			'employeeCount' => $run->employees,
			'meetsThreshold' => $run->employees >= self::THRESHOLD,
			'businessKm' => $this->rows($run->business),
			'commuteKm' => $this->rows($run->commute),
			'totalBusinessKm' => $this->total($run->business),
			'totalCommuteKm' => $this->total($run->commute),
			'tripsWithoutMode' => $run->missing,
			'compiledAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'compiledBy' => $run->userId,
		];
	}//end report()

	/**
	 * The id of the report kept for the administration and year, or null
	 * when there is none yet.
	 *
	 * @param WpmReportRun $run The administration and year.
	 *
	 * @return string|null
	 */
	private function storedReportId(WpmReportRun $run): ?string {
		$existing = $this->gateway->findFiltered('WpmReport', ['administrationId' => $run->administrationId, 'year' => $run->year]);
		$uuid = null;
		if ($existing !== []) {
			$uuid = (string)($existing[0]['id'] ?? '');
		}

		return ($uuid === '' ? null : $uuid);
	}//end storedReportId()

	/**
	 * Business kilometres per mode and fuel from the year's counted claims.
	 *
	 * @param WpmReportRun $run The administration and year; collects the trips without a mode.
	 *
	 * @return array<string, array{transportMode: string, fuelType: string|null, km: float}>
	 */
	private function businessKm(WpmReportRun $run): array {
		$tally = ['groups' => [], 'missing' => $run->missing];
		$claims = $this->gateway->findFiltered('Expense', ['administrationId' => $run->administrationId, 'category' => 'travel', 'travelType' => 'business']);
		foreach ($claims as $claim) {
			if ($this->isCountedTrip(claim: $claim, year: $run->year) === true) {
				$this->add(tally: $tally, trip: ['schema' => 'Expense', 'row' => $claim, 'km' => (float)$claim['distanceKm']]);
			}
		}

		$run->missing = $tally['missing'];
		return $tally['groups'];
	}//end businessKm()

	/**
	 * Whether a business claim counts: a counted status, dated in the year,
	 * with a distance above zero.
	 *
	 * @param array<string, mixed> $claim The travel Expense.
	 * @param int                  $year  The year.
	 *
	 * @return bool
	 */
	private function isCountedTrip(array $claim, int $year): bool {
		$distance = ($claim['distanceKm'] ?? null);

		return (in_array(($claim['status'] ?? null), self::COUNTED_CLAIMS, true) === true
			&& str_starts_with((string)($claim['expenseDate'] ?? ''), $year . '-') === true
			&& is_numeric($distance) === true
			&& (float)$distance > 0.0);
	}//end isCountedTrip()

	/**
	 * Commuting kilometres per mode and fuel from the counted arrangements,
	 * pro rata for the months each was active in the year.
	 *
	 * @param WpmReportRun $run The administration and year; collects the trips without a mode.
	 *
	 * @return array<string, array{transportMode: string, fuelType: string|null, km: float}>
	 */
	private function commuteKm(WpmReportRun $run): array {
		$tally = ['groups' => [], 'missing' => $run->missing];
		foreach ($this->gateway->findFiltered('CommuteArrangement', ['administrationId' => $run->administrationId]) as $arrangement) {
			$kilometres = $this->yearlyKm(arrangement: $arrangement, year: $run->year);
			if ($kilometres > 0.0) {
				$this->add(tally: $tally, trip: ['schema' => 'CommuteArrangement', 'row' => $arrangement, 'km' => $kilometres]);
			}
		}

		$run->missing = $tally['missing'];
		return $tally['groups'];
	}//end commuteKm()

	/**
	 * The kilometres a counted arrangement commutes in the year; zero for
	 * one that does not count or has no distance or days.
	 *
	 * @param array<string, mixed> $arrangement The CommuteArrangement.
	 * @param int                  $year        The year.
	 *
	 * @return float
	 */
	private function yearlyKm(array $arrangement, int $year): float {
		$distance = ($arrangement['distanceKmOneWay'] ?? null);
		$days = ($arrangement['daysPerWeek'] ?? null);
		if (in_array(($arrangement['status'] ?? null), self::COUNTED_ARRANGEMENTS, true) === false || is_numeric($distance) === false || is_numeric($days) === false) {
			return 0.0;
		}

		$months = $this->monthsActive(arrangement: $arrangement, year: $year);
		return $this->calculator->yearlyCommuteKm(distanceKmOneWay: (float)$distance, daysPerWeek: (float)$days, monthsActive: $months);
	}//end yearlyKm()

	/**
	 * Add a trip to its mode and fuel group, or to the missing list.
	 *
	 * @param array{groups: array<string, array{transportMode: string, fuelType: string|null, km: float}>, missing: list<array<string, mixed>>} $tally The groups and the trips without a mode.
	 * @param array{schema: string, row: array<string, mixed>, km: float} $trip The trip's schema, the trip and its kilometres.
	 *
	 * @return void
	 */
	private function add(array &$tally, array $trip): void {
		$row = $trip['row'];
		$mode = trim((string)($row['transportMode'] ?? ''));
		if ($mode === '') {
			$tally['missing'][] = ['schema' => $trip['schema'], 'id' => (string)($row['id'] ?? ''), 'title' => $this->titleOf($row), 'km' => round($trip['km'], 1)];
			return;
		}

		$fuel = trim((string)($row['fuelType'] ?? ''));
		$fuel = ($fuel === '' ? null : $fuel);
		$key = $mode . '|' . ($fuel ?? '');
		if (isset($tally['groups'][$key]) === false) {
			$tally['groups'][$key] = ['transportMode' => $mode, 'fuelType' => $fuel, 'km' => 0.0];
		}

		$tally['groups'][$key]['km'] = round(($tally['groups'][$key]['km'] + $trip['km']), 1);
	}//end add()

	/**
	 * The number of months of the year in which an arrangement is active on
	 * at least one day.
	 *
	 * @param array<string, mixed> $arrangement The arrangement: its startDate, and its endDate or none while it lasts.
	 * @param int                  $year        The year.
	 *
	 * @return int 0 to 12.
	 */
	private function monthsActive(array $arrangement, int $year): int {
		$months = 0;
		for ($month = 1; $month <= 12; $month++) {
			if ($this->activeInMonth(arrangement: $arrangement, first: sprintf('%04d-%02d-01', $year, $month)) === true) {
				$months++;
			}
		}

		return $months;
	}//end monthsActive()

	/**
	 * Whether an arrangement is active on at least one day of a month.
	 *
	 * @param array<string, mixed> $arrangement The arrangement.
	 * @param string               $first       The month's first day.
	 *
	 * @return bool
	 */
	private function activeInMonth(array $arrangement, string $first): bool {
		$start = (string)($arrangement['startDate'] ?? '');
		$end = (string)($arrangement['endDate'] ?? '');
		$last = date('Y-m-t', (int)strtotime($first));

		return ($start === '' || $start <= $last) && ($end === '' || $end >= $first);
	}//end activeInMonth()

	/**
	 * The people of the administration employed on at least one day of the year.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year.
	 *
	 * @return int
	 */
	private function employeeCount(string $administrationId, int $year): int {
		$count = 0;
		foreach ($this->gateway->findFiltered('Employee', ['administrationId' => $administrationId]) as $employee) {
			$start = (string)($employee['startDate'] ?? '');
			$end = (string)($employee['endDate'] ?? '');
			if (($start === '' || $start <= $year . '-12-31') && ($end === '' || $end >= $year . '-01-01')) {
				$count++;
			}
		}

		return $count;
	}//end employeeCount()

	/**
	 * The groups as a list, sorted by mode and fuel.
	 *
	 * @param array<string, array{transportMode: string, fuelType: string|null, km: float}> $groups The groups.
	 *
	 * @return list<array{transportMode: string, fuelType: string|null, km: float}>
	 */
	private function rows(array $groups): array {
		ksort($groups);

		return array_values($groups);
	}//end rows()

	/**
	 * The kilometres of all groups.
	 *
	 * @param array<string, array{transportMode: string, fuelType: string|null, km: float}> $groups The groups.
	 *
	 * @return float
	 */
	private function total(array $groups): float {
		return round(array_sum(array_column($groups, 'km')), 1);
	}//end total()

	/**
	 * A trip's title for the missing list.
	 *
	 * @param array<string, mixed> $trip The trip.
	 *
	 * @return string|null
	 */
	private function titleOf(array $trip): ?string {
		$title = trim((string)($trip['title'] ?? ''));

		return $title === '' ? null : $title;
	}//end titleOf()

}//end class
