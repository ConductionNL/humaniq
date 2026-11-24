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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
class PensionPremiumService {

	/**
	 * @param RecordStoreInterface $store             Reads participations and schemes.
	 * @param float                $fullTimeHoursWeek The hours per week of a full-time employee, as the UPA filing uses.
	 */
	public function __construct(
		private readonly RecordStoreInterface $store,
		private readonly float $fullTimeHoursWeek=AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
	) {
	}//end __construct()

	/**
	 * What the run reads once: the period's last day, the participations by
	 * employee and the administration's schemes.
	 *
	 * @param string $administrationId The run's administration.
	 * @param string $period           The run's period, `YYYY-MM`.
	 *
	 * @return array{lastDay: string, participations: array<string, array<int, array<string, mixed>>>, schemes: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function inputs(string $administrationId, string $period): array {
		$byEmployee = [];
		foreach ($this->store->loadAll('PensionParticipation') as $participation) {
			$byEmployee[(string)($participation['employeeId'] ?? '')][] = $participation;
		}

		$filter = ($administrationId === '' ? [] : ['administrationId' => $administrationId]);
		return [
			'lastDay' => date('Y-m-t', (int)strtotime($period.'-01')),
			'participations' => $byEmployee,
			'schemes' => $this->store->findFiltered('PensionScheme', $filter),
		];
	}//end inputs()

	/**
	 * The pension part of one employee's calculation, or null without a
	 * participation in force or without a scheme for its fund.
	 *
	 * @param array<string, mixed>      $inputs     What inputs() read.
	 * @param string                    $employeeId The employee.
	 * @param int                       $grossCents The period's pensionable salary, in cents.
	 * @param array<string, mixed>|null $contract   The covering contract, for the part-time factor.
	 *
	 * @return PensionPremium|null
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function premiumFor(array $inputs, string $employeeId, int $grossCents, ?array $contract): ?PensionPremium {
		$participation = self::inForce(rows: ($inputs['participations'][$employeeId] ?? []), day: $inputs['lastDay'], from: 'startDate', until: 'endDate');
		if ($participation === null) {
			return null;
		}

		$fund    = (string)($participation['fund'] ?? '');
		$schemes = array_filter($inputs['schemes'], static fn (array $scheme): bool => (string)($scheme['fund'] ?? '') === $fund);
		$scheme  = self::inForce(rows: $schemes, day: $inputs['lastDay'], from: 'validFrom', until: 'validUntil');
		if ($scheme === null) {
			return null;
		}

		$factor  = (is_numeric($participation['participationFactor'] ?? null) === true ? max(0.0, min(1.0, (float)$participation['participationFactor'])) : 1.0);
		$hours   = ($contract['hoursPerWeek'] ?? null);
		$figures = PensionBase::of(
			earnings: [
				'wage' => (($grossCents / 100) * $factor),
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
	 * @param array<int|string, array<string, mixed>> $rows  The rows.
	 * @param string                                  $day   The day, `YYYY-MM-DD`.
	 * @param string                                  $from  The start-date field.
	 * @param string                                  $until The end-date field; empty runs on.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function inForce(array $rows, string $day, string $from, string $until): ?array {
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
