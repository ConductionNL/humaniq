<?php

/**
 * PensionPremiumLookup
 *
 * The pension part of one payslip (payroll-engine-port-from-shillinq D1/D2):
 * the employee's PensionParticipation in force on the last day of the
 * period, the administration's PensionScheme of that fund valid on that
 * day, and the period's pension base computed with the same PensionBase
 * arithmetic the pension filing uses. The pack applies the percentages.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Pension
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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Pension;

use OCA\Humaniq\Payroll\PensionPremium;

/**
 * Pure selection and arithmetic over the participations and schemes a run read once.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PensionBase is pure and static by design, the PensionMessageService precedent.
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
final class PensionPremiumLookup {

	/**
	 * The lookup.
	 *
	 * @param float $fullTimeHoursWeek The hours per week of a full-time employee, as the pension filing counts them.
	 */
	public function __construct(
		private readonly float $fullTimeHoursWeek,
	) {
	}//end __construct()

	/**
	 * The participations per employee and the administration's schemes per fund.
	 *
	 * @param array{participations: list<array<string, mixed>>, schemes: list<array<string, mixed>>} $rows             Every PensionParticipation and PensionScheme.
	 * @param string                                                                                $administrationId The run's administration.
	 *
	 * @return array{participations: array<string, list<array<string, mixed>>>, schemes: array<string, list<array<string, mixed>>>}
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function inputs(array $rows, string $administrationId): array {
		$inputs = ['participations' => [], 'schemes' => []];
		foreach ($rows['participations'] as $participation) {
			$inputs['participations'][(string)($participation['employeeId'] ?? '')][] = $participation;
		}

		foreach ($rows['schemes'] as $scheme) {
			if ((string)($scheme['administrationId'] ?? '') === $administrationId) {
				$inputs['schemes'][(string)($scheme['fund'] ?? '')][] = $scheme;
			}
		}

		return $inputs;
	}//end inputs()

	/**
	 * The pension base and percentages of one payslip, or null when the
	 * employee has no participation in force or its fund has no scheme
	 * valid on the last day of the period.
	 *
	 * @param array{participations: array<string, list<array<string, mixed>>>, schemes: array<string, list<array<string, mixed>>>} $inputs The run's inputs.
	 * @param array{employeeId: string, contract: array<string, mixed>|null, grossCents: int, lastDay: string, periodsPerYear: int}   $slip   The payslip's employee, contract, wage, last day of the period and periods per year.
	 *
	 * @return PensionPremium|null
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function premiumFor(array $inputs, array $slip): ?PensionPremium {
		$participation = self::inForce(rows: ($inputs['participations'][$slip['employeeId']] ?? []), span: ['startDate', 'endDate', $slip['lastDay']]);
		if ($participation === null) {
			return null;
		}

		$scheme = self::inForce(rows: ($inputs['schemes'][(string)($participation['fund'] ?? '')] ?? []), span: ['validFrom', 'validUntil', $slip['lastDay']]);
		if ($scheme === null) {
			return null;
		}

		return new PensionPremium(
			pensionBaseCents: $this->baseCents(slip: array_merge($slip, ['participationFactor' => self::factorOf($participation)]), scheme: $scheme),
			employeePercentage: PensionBase::amount(scheme: $scheme, key: 'employeePercentage'),
			employerPercentage: PensionBase::amount(scheme: $scheme, key: 'employerPercentage')
		);
	}//end premiumFor()

	/**
	 * The period's pension base in cents. PensionBase works per month, so a
	 * four-week or weekly wage is taken to a month and the base back to the
	 * period; the arithmetic is linear, so that equals spreading the
	 * scheme's yearly figures over the period's own count.
	 *
	 * @param array{employeeId: string, contract: array<string, mixed>|null, grossCents: int, lastDay: string, periodsPerYear: int, participationFactor: float} $slip   The payslip and its participation factor.
	 * @param array<string, mixed>                                                                                                                         $scheme The PensionScheme.
	 *
	 * @return int
	 */
	private function baseCents(array $slip, array $scheme): int {
		$toMonth = ($slip['periodsPerYear'] / PensionBase::PERIODS_PER_YEAR);
		$hours = ($slip['contract']['hoursPerWeek'] ?? null);
		if (is_numeric($hours) === false || (float)$hours <= 0.0) {
			// No hours on the contract: the full franchise, never a larger base.
			$hours = $this->fullTimeHoursWeek;
		}

		$figures = PensionBase::of(
			earnings: ['wage' => (max(0, $slip['grossCents']) / 100 * $slip['participationFactor'] * $toMonth), 'hoursPerWeek' => (float)$hours, 'fullTimeHoursWeek' => $this->fullTimeHoursWeek],
			scheme: $scheme
		);

		return (int)round($figures['pensionBase'] / $toMonth * 100);
	}//end baseCents()

	/**
	 * The row in force on a day: started on or before it and not ended
	 * before it; the latest start wins.
	 *
	 * @param list<array<string, mixed>>            $rows The rows.
	 * @param array{0: string, 1: string, 2: string} $span The start field, the end field and the day, YYYY-MM-DD.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function inForce(array $rows, array $span): ?array {
		[$fromKey, $untilKey, $day] = $span;
		$found = null;
		foreach ($rows as $row) {
			$from = substr((string)($row[$fromKey] ?? ''), 0, 10);
			$until = substr((string)($row[$untilKey] ?? ''), 0, 10);
			if ($from === '' || $from > $day || ($until !== '' && $until < $day)) {
				continue;
			}

			if ($found === null || $from > substr((string)($found[$fromKey] ?? ''), 0, 10)) {
				$found = $row;
			}
		}

		return $found;
	}//end inForce()

	/**
	 * The participation factor, 1 when absent, clamped to 0..1.
	 *
	 * @param array<string, mixed> $participation The PensionParticipation.
	 *
	 * @return float
	 */
	private static function factorOf(array $participation): float {
		$factor = ($participation['participationFactor'] ?? null);
		if (is_numeric($factor) === false) {
			return 1.0;
		}

		return min(1.0, max(0.0, (float)$factor));
	}//end factorOf()
}//end class
