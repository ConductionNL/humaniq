<?php

/**
 * The taxed and untaxed part of one recurring allowance for one period.
 *
 * A taxed allowance is wage in full. A free-margin allowance is untaxed in
 * full and charged to the WKR free margin. An allowance under a targeted
 * exemption is untaxed up to its norm where the norm is a number (the
 * home-working norm per day), and the excess is wage; a travel allowance takes
 * its split from the approved commuting arrangement. An allowance whose norm
 * is unverified, or whose amount cannot be read, pays nothing and says why
 * (payroll-expenses-and-allowances D4).
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Pure arithmetic over one allowance, no I/O.
 *
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 */
class AllowanceSplitter {

	/**
	 * The tax treatments an allowance may declare.
	 *
	 * @var list<string>
	 */
	private const TREATMENTS = ['gericht-vrijgesteld', 'vrije-ruimte', 'belast'];

	/**
	 * Split one allowance for one period.
	 *
	 * @param array<string, mixed>                      $allowance   The RecurringAllowance.
	 * @param array<string, mixed>|null                 $arrangement The linked CommuteArrangement, or null.
	 * @param array{perDayCents: int, verified: bool}|null $norm     The home-working norm, or null when the tables lack it.
	 *
	 * @return array{status: string, taxedCents: int, untaxedCents: int, wkrCategory: string|null}
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	public function split(array $allowance, ?array $arrangement, ?array $norm): array {
		$treatment = (string)($allowance['taxTreatment'] ?? '');
		if (in_array($treatment, self::TREATMENTS, true) === false) {
			return self::unpaid('treatment-unknown');
		}

		if ((string)($allowance['kind'] ?? '') === 'reiskosten' && $treatment !== 'belast') {
			return $this->travelSplit(allowance: $allowance, arrangement: $arrangement, treatment: $treatment);
		}

		$amountCents = $this->amountCents($allowance);
		if ($amountCents === null) {
			return self::unpaid('no-amount');
		}

		if ($treatment === 'belast') {
			return self::paid(taxedCents: $amountCents, untaxedCents: 0, treatment: $treatment);
		}

		if ($treatment === 'vrije-ruimte' || (string)($allowance['kind'] ?? '') !== 'thuiswerk') {
			return self::paid(taxedCents: 0, untaxedCents: $amountCents, treatment: $treatment);
		}

		return $this->homeWorkingSplit(allowance: $allowance, amountCents: $amountCents, norm: $norm);
	}//end split()

	/**
	 * Whether an allowance pays in a period: active, and its dates cover it.
	 *
	 * @param array<string, mixed> $allowance The RecurringAllowance.
	 * @param string               $period    The period, YYYY-MM.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	public function covers(array $allowance, string $period): bool {
		$start = substr((string)($allowance['startDate'] ?? ''), 0, 10);
		if ((string)($allowance['status'] ?? '') !== 'active' || $start === '' || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			return false;
		}

		$first = $period . '-01';
		$last = date('Y-m-t', (int)strtotime($first));
		$end = substr((string)($allowance['endDate'] ?? ''), 0, 10);

		return $start <= $last && ($end === '' || $end >= $first);
	}//end covers()

	/**
	 * The monthly amount in cents: amountPerMonth, else amountPerDay times
	 * daysPerMonth; null when neither is complete.
	 *
	 * @param array<string, mixed> $allowance The RecurringAllowance.
	 *
	 * @return int|null
	 */
	private function amountCents(array $allowance): ?int {
		$perMonth = ($allowance['amountPerMonth'] ?? null);
		if (is_numeric($perMonth) === true && (float)$perMonth > 0.0) {
			return self::cents($perMonth);
		}

		$perDay = ($allowance['amountPerDay'] ?? null);
		$days = $this->days($allowance);
		if (is_numeric($perDay) === true && (float)$perDay > 0.0 && $days !== null) {
			return (self::cents($perDay) * $days);
		}

		return null;
	}//end amountCents()

	/**
	 * A home-working allowance: untaxed up to the day norm times the days,
	 * the excess taxed; nothing when the norm or the days are missing.
	 *
	 * @param array<string, mixed>                         $allowance   The RecurringAllowance.
	 * @param int                                          $amountCents The monthly amount.
	 * @param array{perDayCents: int, verified: bool}|null $norm        The norm.
	 *
	 * @return array{status: string, taxedCents: int, untaxedCents: int, wkrCategory: string|null}
	 */
	private function homeWorkingSplit(array $allowance, int $amountCents, ?array $norm): array {
		if ($norm === null || $norm['verified'] !== true) {
			return self::unpaid('norm-unverified');
		}

		$days = $this->days($allowance);
		if ($days === null) {
			return self::unpaid('days-missing');
		}

		$untaxed = min($amountCents, ($norm['perDayCents'] * $days));

		return self::paid(taxedCents: ($amountCents - $untaxed), untaxedCents: $untaxed, treatment: 'gericht-vrijgesteld');
	}//end homeWorkingSplit()

	/**
	 * A travel allowance: the arrangement's tax-free and taxable month.
	 *
	 * @param array<string, mixed>      $allowance   The RecurringAllowance.
	 * @param array<string, mixed>|null $arrangement The CommuteArrangement.
	 * @param string                    $treatment   The declared treatment.
	 *
	 * @return array{status: string, taxedCents: int, untaxedCents: int, wkrCategory: string|null}
	 */
	private function travelSplit(array $allowance, ?array $arrangement, string $treatment): array {
		if ($arrangement === null || (string)($allowance['commuteArrangementId'] ?? '') === '') {
			return self::unpaid('arrangement-missing');
		}

		$taxFree = ($arrangement['taxFreeMonthly'] ?? ($arrangement['monthlyAllowance'] ?? null));
		if (is_numeric($taxFree) === false) {
			return self::unpaid('no-amount');
		}

		$taxable = ($arrangement['taxableMonthly'] ?? 0);

		return self::paid(taxedCents: self::cents(is_numeric($taxable) === true ? $taxable : 0), untaxedCents: self::cents($taxFree), treatment: $treatment);
	}//end travelSplit()

	/**
	 * The day count, or null when it is not a positive whole number.
	 *
	 * @param array<string, mixed> $allowance The RecurringAllowance.
	 *
	 * @return int|null
	 */
	private function days(array $allowance): ?int {
		$days = ($allowance['daysPerMonth'] ?? null);
		if (is_numeric($days) === false || (int)$days <= 0) {
			return null;
		}

		return (int)$days;
	}//end days()

	/**
	 * Euros to whole cents.
	 *
	 * @param mixed $euros A numeric euro amount.
	 *
	 * @return int
	 */
	private static function cents(mixed $euros): int {
		return (int)round(((float)$euros) * 100);
	}//end cents()

	/**
	 * A paid split.
	 *
	 * @param int    $taxedCents   The part that is wage.
	 * @param int    $untaxedCents The part added to net.
	 * @param string $treatment    The treatment, the WKR category of the untaxed part.
	 *
	 * @return array{status: string, taxedCents: int, untaxedCents: int, wkrCategory: string|null}
	 */
	private static function paid(int $taxedCents, int $untaxedCents, string $treatment): array {
		return [
			'status' => 'paid',
			'taxedCents' => $taxedCents,
			'untaxedCents' => $untaxedCents,
			'wkrCategory' => ($untaxedCents > 0 ? $treatment : null),
		];
	}//end paid()

	/**
	 * A split that pays nothing, with its reason.
	 *
	 * @param string $status The reason.
	 *
	 * @return array{status: string, taxedCents: int, untaxedCents: int, wkrCategory: string|null}
	 */
	private static function unpaid(string $status): array {
		return ['status' => $status, 'taxedCents' => 0, 'untaxedCents' => 0, 'wkrCategory' => null];
	}//end unpaid()

}//end class
