<?php

/**
 * Awf Tariff Resolver
 *
 * The one place that decides whether a period's unemployment premium (Awf)
 * is the low or the high rate (filings-premium-differentiation D1). The
 * payroll run, the retro recalculation and the audit rule
 * `nl-awf-laag-hoog-tarief` all read it, so they cannot drift.
 *
 * The rule, from the Handboek Loonheffingen 2026 (maart 2026), paragraaf 7.2
 * and 7.2.2 (source kept with the change):
 * - low for a permanent, written contract (the contract's explicit
 *   `awfTariff` wins when it is set);
 * - always low for a BBL praktijkovereenkomst that is signed and carries no
 *   uitzendbeding;
 * - always low for an employee younger than 21 at the start of the period
 *   who is paid at most 52 hours in a month (48 in a four-week period);
 * - high again for a low contract whose employment ends at most two months
 *   after it began (the early-end review; contracts following each other
 *   without a single day's gap are one employment).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;

/**
 * Resolves the Awf tariff and the reason for it.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
 */
final class AwfTariffResolver {

	/**
	 * The tariff follows from the contract (permanent and written).
	 */
	public const BASIS_CONTRACT = 'contract';

	/**
	 * The contract's explicit `awfTariff` decided it.
	 */
	public const BASIS_EXPLICIT = 'explicit';

	/**
	 * A signed BBL praktijkovereenkomst without an uitzendbeding.
	 */
	public const BASIS_BBL = 'bbl';

	/**
	 * Under 21 at the start of the period, within the hours norm.
	 */
	public const BASIS_YOUNG = 'young-part-time';

	/**
	 * A low contract whose employment ends within two months.
	 */
	public const BASIS_EARLY_END = 'early-end';

	/**
	 * Every other contract: the high rate.
	 */
	public const BASIS_FLEX = 'flex';

	/**
	 * The age below which the hours exception applies.
	 */
	public const YOUNG_AGE = 21;

	/**
	 * Paid-hours norm for a monthly return (Handboek 2026, 7.2).
	 */
	public const MONTH_HOURS_NORM = 52.0;

	/**
	 * Paid-hours norm for a four-week return (Handboek 2026, 7.2).
	 */
	public const FOUR_WEEK_HOURS_NORM = 48.0;

	/**
	 * The bases under which the low rate may be reviewed. The two
	 * exceptions (BBL, young part-timer) are never reviewed (Handboek
	 * 2026, 7.2.3 let op 3 and let op 9).
	 */
	public const REVIEWABLE_BASES = [self::BASIS_CONTRACT, self::BASIS_EXPLICIT];

	/**
	 * The tariff a contract carries on its own, without the employee's age
	 * or hours: what the audit expects on `EmploymentContract.awfTariff`.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return string `low` or `high`.
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 */
	public static function contractTariff(array $contract): string {
		return (self::contractBasis($contract) === self::BASIS_FLEX ? 'high' : 'low');

	}//end contractTariff()

	/**
	 * The tariff for one period, and why.
	 *
	 * @param array<string, mixed> $contract    The covering EmploymentContract.
	 * @param string|null          $dateOfBirth The employee's date of birth (Y-m-d), or null.
	 * @param string               $period      The period, `YYYY-MM` or `YYYY-P##`.
	 * @param float|null           $paidHours   The hours paid in the period, or null when unknown.
	 * @param bool                 $endsEarly   Whether the employment ends within two months of its start.
	 *
	 * @return array{tariff: string, basis: string}
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 */
	public static function resolve(array $contract, ?string $dateOfBirth, string $period, ?float $paidHours, bool $endsEarly=false): array {
		$basis  = self::explicitOrContractBasis($contract);
		$tariff = ($basis === self::BASIS_FLEX || ($basis === self::BASIS_EXPLICIT && trim((string)($contract['awfTariff'] ?? '')) === 'high')) ? 'high' : 'low';
		if ($tariff === 'low' && $endsEarly === true && in_array($basis, self::REVIEWABLE_BASES, true) === true) {
			$tariff = 'high';
			$basis  = self::BASIS_EARLY_END;
		}

		if ($tariff === 'high' && self::youngWithinNorm(dateOfBirth: $dateOfBirth, period: $period, paidHours: $paidHours) === true) {
			return ['tariff' => 'low', 'basis' => self::BASIS_YOUNG];
		}

		return ['tariff' => $tariff, 'basis' => $basis];

	}//end resolve()

	/**
	 * The paid-hours norm of the period's return: 52 a month, 48 a four
	 * weeks.
	 *
	 * @param string $period The period, `YYYY-MM` or `YYYY-P##`.
	 *
	 * @return float
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 */
	public static function hoursNorm(string $period): float {
		return (self::isFourWeekly($period) === true ? self::FOUR_WEEK_HOURS_NORM : self::MONTH_HOURS_NORM);

	}//end hoursNorm()

	/**
	 * Whether a contract's employment ends at most two months after it
	 * began. A contract that follows another of the same employee without a
	 * single day's gap continues that employment (Handboek 2026, 7.2.2): the
	 * two months count from the first start. Ending on the day the two
	 * months are complete is not early.
	 *
	 * @param array<string, mixed>             $contract  The contract that ends.
	 * @param array<int, array<string, mixed>> $contracts Every contract of the same employee.
	 *
	 * @return bool
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 */
	public static function endsEarly(array $contract, array $contracts): bool {
		$end   = self::date((string)($contract['endDate'] ?? ''));
		$start = self::employmentStart($contract, $contracts);
		if ($end === null || $start === null) {
			return false;
		}

		return $end < $start->modify('+2 months');

	}//end endsEarly()

	/**
	 * The basis before age and an early end: an explicit `high` on the
	 * contract, an explicit `low` on a contract that is otherwise flex, or
	 * the contract's own basis.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return string
	 */
	private static function explicitOrContractBasis(array $contract): string {
		$explicit = trim((string)($contract['awfTariff'] ?? ''));
		$basis    = self::contractBasis($contract);
		if ($explicit === 'high' || ($explicit === 'low' && $basis === self::BASIS_FLEX)) {
			return self::BASIS_EXPLICIT;
		}

		return $basis;

	}//end explicitOrContractBasis()

	/**
	 * Why a contract alone is low or high.
	 *
	 * @param array<string, mixed> $contract The EmploymentContract.
	 *
	 * @return string One of the BASIS_ constants (contract, bbl or flex).
	 */
	private static function contractBasis(array $contract): string {
		$type = (string)($contract['type'] ?? '');
		if ($type === 'bbl'
			&& ($contract['bpvOvereenkomstOndertekend'] ?? false) === true
			&& ($contract['uitzendbedingVanToepassing'] ?? false) !== true
		) {
			return self::BASIS_BBL;
		}

		if ($type === 'permanent' && ($contract['writtenContract'] ?? false) === true) {
			return self::BASIS_CONTRACT;
		}

		return self::BASIS_FLEX;

	}//end contractBasis()

	/**
	 * Whether the employee is under 21 on the first day of the period and
	 * paid within the period's hours norm.
	 *
	 * @param string|null $dateOfBirth The date of birth (Y-m-d), or null.
	 * @param string      $period      The period.
	 * @param float|null  $paidHours   The hours paid, or null when unknown.
	 *
	 * @return bool
	 */
	private static function youngWithinNorm(?string $dateOfBirth, string $period, ?float $paidHours): bool {
		$born  = self::date((string)$dateOfBirth);
		$first = self::periodStart($period);
		if ($born === null || $first === null || $paidHours === null) {
			return false;
		}

		if ($born->modify('+' . self::YOUNG_AGE . ' years') <= $first) {
			return false;
		}

		return $paidHours <= self::hoursNorm($period);

	}//end youngWithinNorm()

	/**
	 * The first day of a period: the first of the month, or for a
	 * four-week period the first day of its 28-day block counted from
	 * 1 January.
	 *
	 * @param string $period The period.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function periodStart(string $period): ?DateTimeImmutable {
		if (preg_match('/^(\d{4})-P(\d{2})$/', $period, $match) === 1) {
			$year = self::date($match[1] . '-01-01');
			return $year?->modify('+' . (((int)$match[2] - 1) * 28) . ' days');
		}

		return self::date($period . '-01');

	}//end periodStart()

	/**
	 * The start of the employment a contract belongs to: walks back over
	 * contracts of the same employee that end the day before.
	 *
	 * @param array<string, mixed>             $contract  The contract.
	 * @param array<int, array<string, mixed>> $contracts Every contract of the employee.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function employmentStart(array $contract, array $contracts): ?DateTimeImmutable {
		$start = self::date((string)($contract['startDate'] ?? ''));
		$guard = count($contracts);
		while ($start !== null && $guard-- > 0) {
			$previous = null;
			foreach ($contracts as $other) {
				$otherEnd = self::date((string)($other['endDate'] ?? ''));
				if ($otherEnd !== null && $otherEnd->modify('+1 day')->format('Y-m-d') === $start->format('Y-m-d')) {
					$previous = self::date((string)($other['startDate'] ?? ''));
					break;
				}
			}

			if ($previous === null) {
				break;
			}

			$start = $previous;
		}

		return $start;

	}//end employmentStart()

	/**
	 * Whether a period is a four-week period (`YYYY-P##`).
	 *
	 * @param string $period The period.
	 *
	 * @return bool
	 */
	private static function isFourWeekly(string $period): bool {
		return preg_match('/^\d{4}-P\d{2}$/', $period) === 1;

	}//end isFourWeekly()

	/**
	 * A Y-m-d date (the first ten characters), or null.
	 *
	 * @param string $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function date(string $value): ?DateTimeImmutable {
		$value = substr(trim($value), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}

	}//end date()
}//end class
