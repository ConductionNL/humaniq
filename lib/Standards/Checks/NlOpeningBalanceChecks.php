<?php

/**
 * NL opening-balance checks (payroll-period-edge-cases D6).
 *
 * Two rules over Payslip, against the opening balances imported from a
 * previous payroll package:
 *
 * - `nl-openingsbalans-overlap`: a humaniq payslip in a period on or before
 *   the balance's `lastPeriodInPrevious` counts that period twice in the
 *   annual statement;
 * - `nl-openingsbalans-ontbreekt`: an employee whose first humaniq payslip of
 *   a year is not the year's first period, and who has no opening balance for
 *   that year, gets an annual statement missing the months before.
 *
 * Both read `context['openingBalances']`, built once per audit by
 * RuleAuditService::buildOpeningBalanceContext().
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
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\PayPeriod;

/**
 * Opening balances against humaniq's own payslips.
 */
final class NlOpeningBalanceChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public static function checks(): array {
		return [
			'Payslip' => [
				'nl-openingsbalans-overlap' => static fn (array $o, array $context): bool => self::doesNotOverlap(payslip: $o, context: $context),
				'nl-openingsbalans-ontbreekt' => static fn (array $o, array $context): bool => self::hasBalanceWhenNeeded(payslip: $o, context: $context),
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
	 * Whether the payslip's period comes after the last period the previous
	 * package paid. Satisfied without a balance, or for a period that cannot
	 * be read.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 * @param array<string, mixed> $context The audit context.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public static function doesNotOverlap(array $payslip, array $context): bool {
		$period = self::period(id: (string)($payslip['period'] ?? ''));
		if ($period === null) {
			return true;
		}

		$balance = self::balanceFor(employeeId: (string)($payslip['employeeId'] ?? ''), year: self::yearOf(period: $period), context: $context);
		$previous = self::period(id: (string)($balance['lastPeriodInPrevious'] ?? ''));
		if ($previous === null) {
			return true;
		}

		return $period->firstDay > $previous->lastDay;
	}//end doesNotOverlap()

	/**
	 * Whether an employee whose first humaniq payslip of the year starts after
	 * the year's first period has an opening balance for that year. Only the
	 * first payslip of the year is judged, so one missing balance is one
	 * finding.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 * @param array<string, mixed> $context The audit context.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public static function hasBalanceWhenNeeded(array $payslip, array $context): bool {
		$employeeId = trim((string)($payslip['employeeId'] ?? ''));
		$period = self::period(id: (string)($payslip['period'] ?? ''));
		if ($employeeId === '' || $period === null) {
			return true;
		}

		$year = self::yearOf(period: $period);
		if (($context['openingBalances']['firstPeriod'][$employeeId][$year] ?? null) !== $period->id) {
			// Not the employee's first humaniq payslip of the year.
			return true;
		}

		if (preg_match('/^\d{4}-(01|P01|W01)$/', $period->id) === 1) {
			// humaniq paid the year from its first period.
			return true;
		}

		return self::balanceFor(employeeId: $employeeId, year: $year, context: $context) !== null;
	}//end hasBalanceWhenNeeded()

	/**
	 * The employee's opening balance for the year, if any.
	 *
	 * @param string               $employeeId The employee.
	 * @param int                  $year       The year.
	 * @param array<string, mixed> $context    The audit context.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function balanceFor(string $employeeId, int $year, array $context): ?array {
		$balance = ($context['openingBalances']['byEmployeeYear'][trim($employeeId)][$year] ?? null);

		return is_array($balance) === true ? $balance : null;
	}//end balanceFor()

	/**
	 * A period id as a PayPeriod, or null when it is not one.
	 *
	 * @param string $id The period id.
	 *
	 * @return PayPeriod|null
	 */
	private static function period(string $id): ?PayPeriod {
		try {
			return PayPeriod::parse($id);
		} catch (\InvalidArgumentException $e) {
			return null;
		}
	}//end period()

	/**
	 * The year a period's id names.
	 *
	 * @param PayPeriod $period The period.
	 *
	 * @return int
	 */
	private static function yearOf(PayPeriod $period): int {
		return (int)substr($period->id, 0, 4);
	}//end yearOf()
}//end class
