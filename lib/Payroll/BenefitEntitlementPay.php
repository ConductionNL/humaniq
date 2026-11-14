<?php

/**
 * Humaniq BenefitEntitlementPay
 *
 * What a benefit entitlement pays in one wage period, and under which special
 * regime: whether the entitlement is payable in the period, its gross amount
 * (a fixed monthly amount, or a percentage schedule of a reference salary,
 * less the income to offset), the regime key its kind selects, and why a
 * regime read from the tables may not be paid under yet. Pure: no I/O.
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
 * @spec openspec/changes/payroll-special-regimes
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;
use RuntimeException;

/**
 * The period amount and regime of one benefit entitlement.
 */
final class BenefitEntitlementPay {

	/**
	 * The regime a contract of this type is paid under.
	 *
	 * @var array<string, string>
	 */
	private const CONTRACT_REGIMES = ['political-office' => 'politiek-ambt'];

	/**
	 * The regime an entitlement of this kind is paid under.
	 *
	 * @var array<string, string>
	 */
	private const KIND_REGIMES = [
		'rvu' => 'rvu',
		'wachtgeld' => 'wachtgeld',
	];

	/**
	 * Whether the entitlement is active and pays in the period: its start and
	 * end cover the period and, when both name one, it belongs to the run's
	 * administration. A lump sum pays only in its own month.
	 *
	 * @param array<string, mixed> $entitlement      The BenefitEntitlement row.
	 * @param string               $period           The wage period, `YYYY-MM`.
	 * @param string               $administrationId The run's administration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public static function paysIn(array $entitlement, string $period, string $administrationId): bool {
		if ((string)($entitlement['status'] ?? '') !== 'active') {
			return false;
		}

		$own = trim((string)($entitlement['administrationId'] ?? ''));
		if ($own !== '' && $administrationId !== '' && $own !== $administrationId) {
			return false;
		}

		if ((string)($entitlement['paymentMode'] ?? '') === 'lump-sum') {
			return trim((string)($entitlement['lumpSumPeriod'] ?? '')) === $period;
		}

		$first = self::monthOf((string)($entitlement['startDate'] ?? ''));
		if ($first === null || $first > $period) {
			return false;
		}

		$last = self::monthOf((string)($entitlement['endDate'] ?? ''));

		return $last === null || $last >= $period;
	}//end paysIn()

	/**
	 * The regime key an entitlement's kind selects, or null for an unknown kind.
	 *
	 * @param array<string, mixed> $entitlement The BenefitEntitlement row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public static function regimeOfEntitlement(array $entitlement): ?string {
		return (self::KIND_REGIMES[(string)($entitlement['kind'] ?? '')] ?? null);
	}//end regimeOfEntitlement()

	/**
	 * The regime key a contract's type selects, or null for an ordinary contract.
	 *
	 * @param array<string, mixed>|null $contract The EmploymentContract row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public static function regimeOfContract(?array $contract): ?string {
		if ($contract === null) {
			return null;
		}

		return (self::CONTRACT_REGIMES[(string)($contract['type'] ?? '')] ?? null);
	}//end regimeOfContract()

	/**
	 * Read a regime from the tables, or the reason it may not be paid under:
	 * the tables carry no such regime, or one of its leaves is unverified.
	 *
	 * @param TaxTables $tables The run's tables.
	 * @param string    $regime The regime key.
	 *
	 * @return array{regime: array<string, mixed>|null, reason: string|null}
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public static function readRegime(TaxTables $tables, string $regime): array {
		try {
			$values = $tables->specialRegime($regime);
		} catch (RuntimeException $e) {
			return ['regime' => null, 'reason' => 'regime-unverified (de tabellen ' . $tables->id() . ' kennen het regime ' . $regime . ' niet)'];
		}

		if ($values['unverified'] !== []) {
			return ['regime' => null, 'reason' => 'regime-unverified (niet bevestigd: ' . implode(', ', $values['unverified']) . ')'];
		}

		return ['regime' => $values, 'reason' => null];
	}//end readRegime()

	/**
	 * The gross the entitlement pays in the period, in cents, or the reason it
	 * cannot be computed. A periodic benefit pays its fixed monthly amount or,
	 * with a reference salary, the schedule's percentage of it for the month
	 * counted from the start date; the income to offset comes off, never below
	 * zero. A lump sum pays its amount.
	 *
	 * @param array<string, mixed> $entitlement The BenefitEntitlement row.
	 * @param string               $period      The wage period, `YYYY-MM`.
	 *
	 * @return array{cents: int, reason: string|null}
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public static function grossFor(array $entitlement, string $period): array {
		if ((string)($entitlement['paymentMode'] ?? '') === 'lump-sum') {
			return self::amount(euros: ($entitlement['lumpSumAmount'] ?? null), offset: 0.0, what: 'lumpSumAmount');
		}

		$offset = (float)($entitlement['offsetIncomeMonthly'] ?? 0.0);
		if (is_numeric($entitlement['monthlyAmount'] ?? null) === true) {
			return self::amount(euros: $entitlement['monthlyAmount'], offset: $offset, what: 'monthlyAmount');
		}

		$reference = ($entitlement['referenceSalary'] ?? null);
		if (is_numeric($reference) === false) {
			return ['cents' => 0, 'reason' => 'no-amount (de uitkering noemt geen maandbedrag en geen referentiesalaris)'];
		}

		$month = self::monthNumber(startDate: (string)($entitlement['startDate'] ?? ''), period: $period);
		foreach ((array)($entitlement['schedule'] ?? []) as $stretch) {
			if (is_array($stretch) === true && $month >= (int)($stretch['fromMonth'] ?? 0) && $month <= (int)($stretch['toMonth'] ?? 0)) {
				return self::amount(euros: ((float)$reference * (float)($stretch['percentage'] ?? 0.0) / 100), offset: $offset, what: 'schedule');
			}
		}

		return ['cents' => 0, 'reason' => 'no-amount (het schema dekt maand ' . $month . ' van de uitkering niet)'];
	}//end grossFor()

	/**
	 * A euro amount less the offset, in cents, never below zero.
	 *
	 * @param mixed  $euros  The amount.
	 * @param float  $offset The income to offset.
	 * @param string $what   The field, for the reason.
	 *
	 * @return array{cents: int, reason: string|null}
	 */
	private static function amount(mixed $euros, float $offset, string $what): array {
		if (is_numeric($euros) === false) {
			return ['cents' => 0, 'reason' => 'no-amount (' . $what . ' ontbreekt)'];
		}

		return ['cents' => max(0, (int)round((((float)$euros - $offset) * 100))), 'reason' => null];
	}//end amount()

	/**
	 * The month of the benefit the period is, counting the start month as 1.
	 *
	 * @param string $startDate The entitlement's start date.
	 * @param string $period    The wage period, `YYYY-MM`.
	 *
	 * @return int
	 */
	private static function monthNumber(string $startDate, string $period): int {
		$first = self::monthOf($startDate);
		if ($first === null) {
			return 0;
		}

		[$fromYear, $fromMonth] = array_map('intval', explode('-', $first));
		[$year, $month] = array_map('intval', explode('-', $period));

		return ((($year - $fromYear) * 12) + ($month - $fromMonth) + 1);
	}//end monthNumber()

	/**
	 * The `YYYY-MM` month of a date, or null when it is empty or not a date.
	 *
	 * @param string $date The date.
	 *
	 * @return string|null
	 */
	private static function monthOf(string $date): ?string {
		$date = trim($date);
		if ($date === '') {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
		if ($parsed === false) {
			return null;
		}

		return $parsed->format('Y-m');
	}//end monthOf()

}//end class
