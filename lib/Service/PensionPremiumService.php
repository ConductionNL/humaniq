<?php
/**
 * Pension Premium Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pension part of a payroll run (payroll-engine-port-from-shillinq D1,
 * D2): for each employee, the participation in force on the last day of the
 * period, the administration's scheme of that fund valid on that day, and
 * the pension base PensionBase computes from them, the same arithmetic the
 * UPA filing reports. The jurisdiction pack takes the premiums of that base.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\Pension\PensionBase;
use OCA\Humaniq\Payroll\PensionPremium;

/**
 * Resolves each employee's pension base and percentages for one run.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PensionBase is pure and static by design, the PensionMessageService precedent.
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
class PensionPremiumService {

	/**
	 * @param float $fullTimeHoursWeek The hours per week of a full-time employee, as the UPA filing uses.
	 */
	public function __construct(
		private readonly float $fullTimeHoursWeek=AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
	) {
	}//end __construct()

	/**
	 * What the run reads once: the period's last day, the participations by
	 * employee and the administration's schemes. The run passes the rows it
	 * read; this class reads nothing itself.
	 *
	 * @param array{participations: array<int, array<string, mixed>>, schemes: array<int, array<string, mixed>>} $rows Every PensionParticipation and PensionScheme.
	 * @param array{administrationId: string, period: string}                                                 $run  The run's administration (empty takes every scheme) and period, `YYYY-MM`.
	 *
	 * @return array{lastDay: string, participations: array<string, array<int, array<string, mixed>>>, schemes: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function inputs(array $rows, array $run): array {
		['administrationId' => $administrationId, 'period' => $period] = $run;
		$byEmployee = [];
		foreach ($rows['participations'] as $participation) {
			$byEmployee[(string)($participation['employeeId'] ?? '')][] = $participation;
		}

		return [
			'lastDay' => date('Y-m-t', (int)strtotime($period.'-01')),
			'participations' => $byEmployee,
			'schemes' => array_values(array_filter($rows['schemes'], static fn (array $scheme): bool => $administrationId === '' || (string)($scheme['administrationId'] ?? '') === $administrationId)),
		];
	}//end inputs()

	/**
	 * The pension part of one employee's calculation, or null without a
	 * participation in force or without a scheme for its fund.
	 *
	 * @param array<string, mixed> $inputs What inputs() read.
	 * @param array{employeeId: string, regularWageCents: int, contract: array<string, mixed>|null} $slip The employee, the period's pensionable salary in cents and the covering contract (for the part-time factor).
	 *
	 * @return PensionPremium|null
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function premiumFor(array $inputs, array $slip): ?PensionPremium {
		$participation = self::inForce(rows: ($inputs['participations'][(string)$slip['employeeId']] ?? []), window: [$inputs['lastDay'], 'startDate', 'endDate']);
		if ($participation === null) {
			return null;
		}

		$fund    = (string)($participation['fund'] ?? '');
		$schemes = array_filter($inputs['schemes'], static fn (array $scheme): bool => (string)($scheme['fund'] ?? '') === $fund);
		$scheme  = self::inForce(rows: $schemes, window: [$inputs['lastDay'], 'validFrom', 'validUntil']);
		if ($scheme === null) {
			return null;
		}

		$factor  = (is_numeric($participation['participationFactor'] ?? null) === true ? max(0.0, min(1.0, (float)$participation['participationFactor'])) : 1.0);
		$hours   = ($slip['contract']['hoursPerWeek'] ?? null);
		$figures = PensionBase::of(
			earnings: [
				'wage' => (((int)$slip['regularWageCents'] / 100) * $factor),
				'hoursPerWeek' => (is_numeric($hours) === true && (float)$hours > 0.0 ? (float)$hours : $this->fullTimeHoursWeek),
				'fullTimeHoursWeek' => $this->fullTimeHoursWeek,
			],
			scheme: $scheme
		);

		return new PensionPremium(
			pensionBaseCents: (int)round($figures['pensionBase'] * 100),
			employeePct: max(0.0, (float)($scheme['employeePercentage'] ?? 0)),
			employerPct: max(0.0, (float)($scheme['employerPercentage'] ?? 0))
		);
	}//end premiumFor()

	/**
	 * The row whose date range covers the day; the latest started when
	 * several do (an overlap the participation guard refuses, but a scheme
	 * may be re-issued).
	 *
	 * @param array<int|string, array<string, mixed>> $rows   The rows.
	 * @param array{0: string, 1: string, 2: string}  $window The day (`YYYY-MM-DD`), the start-date field and the end-date field (empty runs on).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function inForce(array $rows, array $window): ?array {
		[$day, $from, $until] = $window;
		$found = null;
		foreach ($rows as $row) {
			$start = (string)($row[$from] ?? '');
			$end   = (string)($row[$until] ?? '');
			if ($start === '' || $start > $day || ($end !== '' && $end < $day)) {
				continue;
			}

			if ($found === null || $start > (string)$found[$from]) {
				$found = $row;
			}
		}

		return $found;
	}//end inForce()
}//end class
