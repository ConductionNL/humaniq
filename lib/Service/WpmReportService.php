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
	 * @param HoursRegisterGateway      $gateway    Reads and writes the register.
	 * @param TravelAllowanceCalculator $calculator The 214-day arithmetic.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
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
		$missing = [];
		$business = $this->businessKm($administrationId, $year, $missing);
		$commute = $this->commuteKm($administrationId, $year, $missing);
		$employees = $this->employeeCount($administrationId, $year);

		$report = [
			'administrationId' => $administrationId,
			'year' => $year,
			'employeeCount' => $employees,
			'meetsThreshold' => $employees >= self::THRESHOLD,
			'businessKm' => $this->rows($business),
			'commuteKm' => $this->rows($commute),
			'totalBusinessKm' => $this->total($business),
			'totalCommuteKm' => $this->total($commute),
			'tripsWithoutMode' => $missing,
			'compiledAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'compiledBy' => $userId,
		];

		$existing = $this->gateway->findFiltered('WpmReport', ['administrationId' => $administrationId, 'year' => $year]);
		$uuid = null;
		if ($existing !== []) {
			$uuid = (string)($existing[0]['id'] ?? '');
		}

		$saved = $this->gateway->save(payload: $report, schema: 'WpmReport', uuid: ($uuid === '' ? null : $uuid));

		return array_merge($report, ['id' => (string)$saved->getUuid()]);
	}//end compile()

	/**
	 * Business kilometres per mode and fuel from the year's counted claims.
	 *
	 * @param string                           $administrationId The administration.
	 * @param int                              $year             The year.
	 * @param list<array<string, mixed>>       $missing          Collects the trips without a mode.
	 *
	 * @return array<string, array{transportMode: string, fuelType: string|null, km: float}>
	 */
	private function businessKm(string $administrationId, int $year, array &$missing): array {
		$groups = [];
		$claims = $this->gateway->findFiltered('Expense', ['administrationId' => $administrationId, 'category' => 'travel', 'travelType' => 'business']);
		foreach ($claims as $claim) {
			$distance = ($claim['distanceKm'] ?? null);
			if (in_array(($claim['status'] ?? null), self::COUNTED_CLAIMS, true) === false
				|| str_starts_with((string)($claim['expenseDate'] ?? ''), $year . '-') === false
				|| is_numeric($distance) === false
				|| (float)$distance <= 0.0
			) {
				continue;
			}

			$this->add($groups, $missing, 'Expense', $claim, (float)$distance);
		}

		return $groups;
	}//end businessKm()

	/**
	 * Commuting kilometres per mode and fuel from the counted arrangements,
	 * pro rata for the months each was active in the year.
	 *
	 * @param string                           $administrationId The administration.
	 * @param int                              $year             The year.
	 * @param list<array<string, mixed>>       $missing          Collects the trips without a mode.
	 *
	 * @return array<string, array{transportMode: string, fuelType: string|null, km: float}>
	 */
	private function commuteKm(string $administrationId, int $year, array &$missing): array {
		$groups = [];
		foreach ($this->gateway->findFiltered('CommuteArrangement', ['administrationId' => $administrationId]) as $arrangement) {
			$distance = ($arrangement['distanceKmOneWay'] ?? null);
			$days = ($arrangement['daysPerWeek'] ?? null);
			if (in_array(($arrangement['status'] ?? null), self::COUNTED_ARRANGEMENTS, true) === false || is_numeric($distance) === false || is_numeric($days) === false) {
				continue;
			}

			$months = $this->monthsActive((string)($arrangement['startDate'] ?? ''), (string)($arrangement['endDate'] ?? ''), $year);
			$kilometres = $this->calculator->yearlyCommuteKm(distanceKmOneWay: (float)$distance, daysPerWeek: (float)$days, monthsActive: $months);
			if ($kilometres <= 0.0) {
				continue;
			}

			$this->add($groups, $missing, 'CommuteArrangement', $arrangement, $kilometres);
		}

		return $groups;
	}//end commuteKm()

	/**
	 * Add a trip to its mode and fuel group, or to the missing list.
	 *
	 * @param array<string, array{transportMode: string, fuelType: string|null, km: float}> $groups     The groups.
	 * @param list<array<string, mixed>>                                                   $missing    The trips without a mode.
	 * @param string                                                                       $schema     The trip's schema.
	 * @param array<string, mixed>                                                         $trip       The trip.
	 * @param float                                                                        $kilometres Its kilometres.
	 *
	 * @return void
	 */
	private function add(array &$groups, array &$missing, string $schema, array $trip, float $kilometres): void {
		$mode = trim((string)($trip['transportMode'] ?? ''));
		if ($mode === '') {
			$missing[] = ['schema' => $schema, 'id' => (string)($trip['id'] ?? ''), 'title' => $this->titleOf($trip), 'km' => round($kilometres, 1)];
			return;
		}

		$fuel = trim((string)($trip['fuelType'] ?? ''));
		$fuel = ($fuel === '' ? null : $fuel);
		$key = $mode . '|' . ($fuel ?? '');
		if (isset($groups[$key]) === false) {
			$groups[$key] = ['transportMode' => $mode, 'fuelType' => $fuel, 'km' => 0.0];
		}

		$groups[$key]['km'] = round(($groups[$key]['km'] + $kilometres), 1);
	}//end add()

	/**
	 * The number of months of the year in which a period is active on at
	 * least one day.
	 *
	 * @param string $start The first day, `YYYY-MM-DD`.
	 * @param string $end   The last day, or empty while it lasts.
	 * @param int    $year  The year.
	 *
	 * @return int 0 to 12.
	 */
	private function monthsActive(string $start, string $end, int $year): int {
		$months = 0;
		for ($month = 1; $month <= 12; $month++) {
			$first = sprintf('%04d-%02d-01', $year, $month);
			$last = date('Y-m-t', (int)strtotime($first));
			if (($start === '' || $start <= $last) && ($end === '' || $end >= $first)) {
				$months++;
			}
		}

		return $months;
	}//end monthsActive()

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
