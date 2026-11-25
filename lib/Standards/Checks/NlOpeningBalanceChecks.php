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
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): both rules are declared as OpenRegister decision
 * tables via the ProvidesTables capability. The period arithmetic stays here
 * as the derivation; the shared Dmn\DecisionTableEvaluator decides. The
 * closure predicates stay public as doesNotOverlap() and
 * hasBalanceWhenNeeded(), and legacyChecks() lists them for
 * NlOpeningBalanceTableParityTest -- never registered in the engine -- until
 * staged retirement (REQ-RULE-009).
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
 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\PayPeriod;

/**
 * Opening balances against humaniq's own payslips.
 *
 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */
final class NlOpeningBalanceChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: both rules this provider owns are table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];
	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * `nl-openingsbalans-overlap`: a payslip whose period does not start after
	 * the balance's last previous period fails; no readable period or no
	 * balance derives null and passes. `nl-openingsbalans-ontbreekt`: the
	 * employee's first payslip of the year, not in the year's first period,
	 * without a balance fails; no employee or unreadable period derives null.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public static function tables(): array {
		return [
			'Payslip' => [
				'nl-openingsbalans-overlap' => [
					'derive' => static fn (array $payslip, array $context): ?array => self::overlapInputs(payslip: $payslip, context: $context),
					'table' => self::satisfiedTable(
						[['name' => 'startsAfterPrevious', 'type' => 'boolean']],
						[['id' => 'period-already-paid', 'inputEntries' => ['false'], 'outputEntries' => [false]]]
					),
				],
				'nl-openingsbalans-ontbreekt' => [
					'derive' => static fn (array $payslip, array $context): ?array => self::missingBalanceInputs(payslip: $payslip, context: $context),
					'table' => self::satisfiedTable(
						[['name' => 'firstOfYear', 'type' => 'boolean'], ['name' => 'yearStart', 'type' => 'boolean'], ['name' => 'hasBalance', 'type' => 'boolean']],
						[['id' => 'mid-year-start-without-balance', 'inputEntries' => ['true', 'false', 'false'], 'outputEntries' => [false]]]
					),
				],
			],
		];
	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
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
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
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
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
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
	 * The overlap table's input: whether the payslip's period starts after the
	 * last period the previous package paid. Null without a readable period or
	 * without a balance naming a readable last period.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 * @param array<string, mixed> $context The audit context.
	 *
	 * @return array{startsAfterPrevious: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function overlapInputs(array $payslip, array $context): ?array {
		$period = self::period(id: (string)($payslip['period'] ?? ''));
		if ($period === null) {
			return null;
		}

		$balance = self::balanceFor(employeeId: (string)($payslip['employeeId'] ?? ''), year: self::yearOf(period: $period), context: $context);
		$previous = self::period(id: (string)($balance['lastPeriodInPrevious'] ?? ''));
		if ($previous === null) {
			return null;
		}

		return ['startsAfterPrevious' => ($period->firstDay > $previous->lastDay)];
	}//end overlapInputs()

	/**
	 * The missing-balance table's inputs: whether this is the employee's first
	 * humaniq payslip of the year, whether its period is the year's first, and
	 * whether a balance for that year is on file. Null without an employee or
	 * a readable period.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 * @param array<string, mixed> $context The audit context.
	 *
	 * @return array{firstOfYear: bool, yearStart: bool, hasBalance: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function missingBalanceInputs(array $payslip, array $context): ?array {
		$employeeId = trim((string)($payslip['employeeId'] ?? ''));
		$period = self::period(id: (string)($payslip['period'] ?? ''));
		if ($employeeId === '' || $period === null) {
			return null;
		}

		$year = self::yearOf(period: $period);

		return [
			'firstOfYear' => (($context['openingBalances']['firstPeriod'][$employeeId][$year] ?? null) === $period->id),
			'yearStart' => (preg_match('/^\d{4}-(01|P01|W01)$/', $period->id) === 1),
			'hasBalance' => (self::balanceFor(employeeId: $employeeId, year: $year, context: $context) !== null),
		];
	}//end missingBalanceInputs()

	/**
	 * The employee's opening balance for the year, if any.
	 *
	 * @param string               $employeeId The employee.
	 * @param int                  $year       The year.
	 * @param array<string, mixed> $context    The audit context.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
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
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PayPeriod::parse is the pure value-object factory every period reader uses.
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
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	private static function yearOf(PayPeriod $period): int {
		return (int)substr($period->id, 0, 4);
	}//end yearOf()

	/**
	 * Before the tables, both opening-balance rules were these two public
	 * predicates. NlOpeningBalanceTableParityTest holds tables() against them;
	 * the engine never registers this list.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public static function legacyChecks(): array {
		return [
			'Payslip' => [
				'nl-openingsbalans-overlap' => static fn (array $payslip, array $context): bool => self::doesNotOverlap(payslip: $payslip, context: $context),
				'nl-openingsbalans-ontbreekt' => static fn (array $payslip, array $context): bool => self::hasBalanceWhenNeeded(payslip: $payslip, context: $context),
			],
		];
	}//end legacyChecks()
}//end class
