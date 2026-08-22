<?php

/**
 * NL Leave Balance Check Provider
 *
 * Executable checks for the Dutch statutory-leave rules of the labour corpus
 * (lib/Standards/rules/labour.json, framework bw7-10), mapped onto the
 * LeaveBalance object type (leave-verzuim-mvp): the yearly entitlement must be
 * at least 4x the contractual weekly hours (nl-verlof-wettelijk-minimum,
 * evaluated against the contractHoursPerWeek snapshot — RuleEngine predicates
 * are single-object, see design D3), used hours must never exceed the total
 * entitlement (nl-verlof-saldo-niet-negatief), and a balance carrying
 * statutory hours must record the correct 1-July-following-year vervaltermijn
 * (nl-verlof-vervaltermijn, BW art. 7:640a). All three predicates are
 * single-object and side-effect free; the seed LeaveBalance objects live in
 * lib/Settings/register.d/hr-seed.json (ADR-001), not via SeedsObjects — the
 * seeds are deliberately chosen to exercise each rule (compliant / over-used /
 * under-granted), which a single self-contained "always compliant" sample
 * could not demonstrate.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/changes/leave-verzuim-mvp/specs/leave-management/spec.md
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Dutch statutory-leave (verlofsaldo) executable checks.
 */
final class NlLeaveChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'LeaveBalance' => [
				// BW art. 7:634 — the yearly entitlement is at least 4x the
				// contractual weekly working time.
				'nl-verlof-wettelijk-minimum' => static fn (array $o): bool => self::wettelijkMinimumSatisfied($o),
				// BW art. 7:634 jo. 7:638 — used hours never exceed the total entitlement.
				'nl-verlof-saldo-niet-negatief' => static fn (array $o): bool => self::saldoNietNegatiefSatisfied($o),
				// BW art. 7:640a — statutory hours lapse 1 July of the following year.
				'nl-verlof-vervaltermijn' => static fn (array $o): bool => self::vervaltermijnSatisfied($o),
				// leave-buy-sell — audit-time backstop: bovenwettelijkHours may never go negative.
				'nl-verlof-bovenwettelijk-niet-negatief' => static fn (array $o): bool => self::bovenwettelijkNietNegatiefSatisfied($o),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True when the balance's entitledHours is at least 4x the
	 * contractHoursPerWeek snapshot, or the snapshot is absent (not decidable
	 * from this object alone — vacuous pass, per SCHEMA.md machineCheckable
	 * discipline).
	 *
	 * @param array<string, mixed> $o The LeaveBalance.
	 *
	 * @return bool
	 */
	private static function wettelijkMinimumSatisfied(array $o): bool {
		$contractHoursPerWeek = ($o['contractHoursPerWeek'] ?? null);
		if ($contractHoursPerWeek === null || $contractHoursPerWeek === '') {
			return true;
		}

		$entitledHours = (float)($o['entitledHours'] ?? 0);
		return $entitledHours >= (4 * (float)$contractHoursPerWeek);
	}//end wettelijkMinimumSatisfied()

	/**
	 * True when usedHours does not exceed entitledHours + bovenwettelijkHours.
	 *
	 * @param array<string, mixed> $o The LeaveBalance.
	 *
	 * @return bool
	 */
	private static function saldoNietNegatiefSatisfied(array $o): bool {
		$usedHours = (float)($o['usedHours'] ?? 0);
		$total = ((float)($o['entitledHours'] ?? 0) + (float)($o['bovenwettelijkHours'] ?? 0));

		return $usedHours <= $total;
	}//end saldoNietNegatiefSatisfied()

	/**
	 * True when the balance carries no positive entitlement (nothing to
	 * lapse), or expiryDate equals 1 July of the year following `year`. A
	 * missing/blank expiryDate on a balance that does carry entitled hours is
	 * a violation (null included, per REQ-LVM-004).
	 *
	 * @param array<string, mixed> $o The LeaveBalance.
	 *
	 * @return bool
	 */
	private static function vervaltermijnSatisfied(array $o): bool {
		$entitledHours = (float)($o['entitledHours'] ?? 0);
		if ($entitledHours <= 0) {
			return true;
		}

		$year = (int)($o['year'] ?? 0);
		if ($year <= 0) {
			// Year is a required schema field; when absent the object shape
			// itself is invalid and not this check's concern to flag.
			return true;
		}

		$expected = sprintf('%04d-07-01', ($year + 1));
		return (string)($o['expiryDate'] ?? '') === $expected;
	}//end vervaltermijnSatisfied()

	/**
	 * True when the balance's bovenwettelijkHours is not negative
	 * (leave-buy-sell design.md D3) — the audit-time backstop alongside the
	 * write-time LeaveBuySellApprovalGuard/LeaveBuySellSettlementService
	 * checks that keep a sell from ever drawing the balance below zero.
	 *
	 * @param array<string, mixed> $o The LeaveBalance.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-003
	 */
	private static function bovenwettelijkNietNegatiefSatisfied(array $o): bool {
		return (float)($o['bovenwettelijkHours'] ?? 0) >= 0.0;
	}//end bovenwettelijkNietNegatiefSatisfied()

}//end class
