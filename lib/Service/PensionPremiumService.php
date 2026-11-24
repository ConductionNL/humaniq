<?php

/**
 * PensionPremiumService
 *
 * The pension part of one payslip (payroll-engine-port-from-shillinq D1/D2):
 * the employee's PensionParticipation in force on the last day of the
 * period, the administration's PensionScheme of that fund valid on that
 * day, and the period's pension base computed with the same PensionBase
 * arithmetic the pension filing uses. The pack applies the percentages.
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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\Pension\PensionBase;
use OCA\Humaniq\Payroll\PensionPremium;

/**
 * Reads participations and schemes once per run and answers per payslip.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PensionBase is pure and static by design, the PensionMessageService precedent.
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
class PensionPremiumService {

	/**
	 * The service.
	 *
	 * @param RecordStoreInterface $gateway           Register reads.
	 * @param float                $fullTimeHoursWeek The hours per week of a full-time employee.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly float $fullTimeHoursWeek=AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
	) {
	}//end __construct()

	/**
	 * The participations per employee and the administration's schemes per
	 * fund, read once for a run.
	 *
	 * @param string $administrationId The run's administration.
	 *
	 * @return array{participations: array<string, list<array<string, mixed>>>, schemes: array<string, list<array<string, mixed>>>}
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function inputs(string $administrationId): array {
		$inputs = ['participations' => [], 'schemes' => []];
		foreach ($this->gateway->loadAll('PensionParticipation') as $participation) {
			$inputs['participations'][(string)($participation['employeeId'] ?? '')][] = $participation;
		}

		foreach ($this->gateway->findFiltered('PensionScheme', ['administrationId' => $administrationId]) as $scheme) {
			$inputs['schemes'][(string)($scheme['fund'] ?? '')][] = $scheme;
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
		$participation = self::inForce(rows: ($inputs['participations'][$slip['employeeId']] ?? []), span: ['startDate', 'endDate'], day: $slip['lastDay']);
		if ($participation === null) {
			return null;
		}

		$scheme = self::inForce(rows: ($inputs['schemes'][(string)($participation['fund'] ?? '')] ?? []), span: ['validFrom', 'validUntil'], day: $slip['lastDay']);
		if ($scheme === null) {
			return null;
		}

		return new PensionPremium(
			pensionBaseCents: $this->baseCents(slip: $slip, participationFactor: self::factorOf($participation), scheme: $scheme),
			employeePercentage: self::percentage(scheme: $scheme, key: 'employeePercentage'),
			employerPercentage: self::percentage(scheme: $scheme, key: 'employerPercentage')
		);
	}//end premiumFor()

	/**
	 * The period's pension base in cents. PensionBase works per month, so a
	 * four-week or weekly wage is taken to a month and the base back to the
	 * period; the arithmetic is linear, so that equals spreading the
	 * scheme's yearly figures over the period's own count.
	 *
	 * @param array{employeeId: string, contract: array<string, mixed>|null, grossCents: int, lastDay: string, periodsPerYear: int} $slip                The payslip.
	 * @param float                                                                                                                 $participationFactor The participation factor.
	 * @param array<string, mixed>                                                                                                  $scheme              The PensionScheme.
	 *
	 * @return int
	 */
	private function baseCents(array $slip, float $participationFactor, array $scheme): int {
		$toMonth = ($slip['periodsPerYear'] / PensionBase::PERIODS_PER_YEAR);
		$hours = ($slip['contract']['hoursPerWeek'] ?? null);
		if (is_numeric($hours) === false || (float)$hours <= 0.0) {
			// No hours on the contract: the full franchise, never a larger base.
			$hours = $this->fullTimeHoursWeek;
		}

		$figures = PensionBase::of(
			earnings: ['wage' => (max(0, $slip['grossCents']) / 100 * $participationFactor * $toMonth), 'hoursPerWeek' => (float)$hours, 'fullTimeHoursWeek' => $this->fullTimeHoursWeek],
			scheme: $scheme
		);

		return (int)round($figures['pensionBase'] / $toMonth * 100);
	}//end baseCents()

	/**
	 * The row in force on a day: started on or before it and not ended
	 * before it; the latest start wins.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @param array{0: string, 1: string} $span The start and end field.
	 * @param string                     $day  The day, YYYY-MM-DD.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function inForce(array $rows, array $span, string $day): ?array {
		[$fromKey, $untilKey] = $span;
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

	/**
	 * A percentage of the scheme; absent or not a number counts as zero.
	 *
	 * @param array<string, mixed> $scheme The PensionScheme.
	 * @param string               $key    The percentage.
	 *
	 * @return float
	 */
	private static function percentage(array $scheme, string $key): float {
		$value = ($scheme[$key] ?? null);
		if (is_numeric($value) === false) {
			return 0.0;
		}

		return max(0.0, (float)$value);
	}//end percentage()
}//end class
