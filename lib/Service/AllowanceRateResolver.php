<?php

/**
 * Allowance Rate Resolver
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A percentage-of-wage allowance a contract carries, such as the end-of-year
 * payment or the individual choice budget (payroll-reservation-payouts D7,
 * D8): the contract's own rate first, then the collective agreement's
 * `allowances` leaf when it is confirmed. An unverified or placeholder leaf
 * resolves to nothing, so a figure nobody confirmed is never paid; the same
 * contract-first, CAO-second order EmploymentTermsResolver uses.
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
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-an-end-of-year-payment-shall-be-reserved-and-paid-when-a-rate-applies-req-rsp-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Standards\CaoRegistry;

/**
 * Contract rate first, confirmed CAO rate second.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-an-end-of-year-payment-shall-be-reserved-and-paid-when-a-rate-applies-req-rsp-003
 *
 * @SuppressWarnings(PHPMD.StaticAccess) CaoRegistry is the static corpus reader the terms resolver uses too.
 */
class AllowanceRateResolver {

	/**
	 * The rate of one allowance for a contract.
	 *
	 * @param array<string, mixed> $contract      The EmploymentContract.
	 * @param string               $contractField The contract's own rate field, such as `ikbRate`.
	 * @param string               $caoKey        The key in the CAO `allowances` leaf, such as `ikb`.
	 *
	 * @return float|null The percentage (0-100), or null when neither applies.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-an-end-of-year-payment-shall-be-reserved-and-paid-when-a-rate-applies-req-rsp-003
	 */
	public function rate(array $contract, string $contractField, string $caoKey): ?float {
		$own = self::percent(value: ($contract[$contractField] ?? null));
		if ($own !== null) {
			return $own;
		}

		$caoId = trim((string)($contract['cao'] ?? ''));
		$leaf = ($caoId === '' ? null : (CaoRegistry::get($caoId)['allowances'] ?? null));
		if (self::usable(leaf: $leaf) === false) {
			return null;
		}

		$entry = (((array)($leaf['value'] ?? []))[$caoKey] ?? null);

		return self::percent(value: (is_array($entry) === true ? ($entry['pct'] ?? null) : $entry));
	}//end rate()

	/**
	 * Whether a CAO leaf is confirmed: verified and not a placeholder.
	 *
	 * @param mixed $leaf The leaf.
	 *
	 * @return bool
	 */
	private static function usable(mixed $leaf): bool {
		return is_array($leaf) === true && ($leaf['verified'] ?? false) === true && ($leaf['placeholder'] ?? false) !== true;
	}//end usable()

	/**
	 * A non-negative percentage, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null
	 */
	private static function percent(mixed $value): ?float {
		return (is_numeric($value) === true && (float)$value >= 0) ? (float)$value : null;
	}//end percent()
}//end class
