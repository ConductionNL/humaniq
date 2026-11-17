<?php

/**
 * Payslip Line Causes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What differs between two payslips' lines (payroll-mutation-lineage D5):
 * lines are matched on (component, sourceSchema, sourceId), and a line that
 * is only in the later payslip is `new`, only in the earlier one `gone`, and
 * in both with another amount `changed`. When either payslip has no lines
 * (it was calculated before lineage existed) the causes are unknown: null,
 * never an empty list, so "no lineage" is not read as "no cause".
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
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-corrections-and-mutation-reports-shall-point-at-payslip-lines-req-pml-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Compares two payslips' lines.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-corrections-and-mutation-reports-shall-point-at-payslip-lines-req-pml-003
 */
final class PayslipLineCauses {

	/**
	 * The lines that are new, gone or of another amount, or null when either
	 * side has no lines.
	 *
	 * @param array<int, array<string, mixed>> $fromLines The earlier payslip's lines.
	 * @param array<int, array<string, mixed>> $toLines   The later payslip's lines.
	 *
	 * @return array<int, array<string, mixed>>|null Each `change`, `component`, `sourceSchema`, `sourceId`, `sourceKind`, `amountBefore`, `amountAfter`.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/tasks.md#task-2.5
	 */
	public static function between(array $fromLines, array $toLines): ?array {
		if ($fromLines === [] || $toLines === []) {
			return null;
		}

		$from = self::byKey($fromLines);
		$to   = self::byKey($toLines);

		return array_merge(self::arrivals(from: $from, to: $to), self::departures(from: $from, to: $to));
	}//end between()

	/**
	 * The later lines that are new or of another amount, in the later order.
	 *
	 * @param array<string, array<string, mixed>> $from The earlier lines by key.
	 * @param array<string, array<string, mixed>> $to   The later lines by key.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function arrivals(array $from, array $to): array {
		$causes = [];
		foreach ($to as $key => $line) {
			$before = ($from[$key] ?? null);
			if ($before === null) {
				$causes[] = self::cause(line: $line, delta: ['change' => 'new', 'amountBefore' => null, 'amountAfter' => $line['amount']]);
				continue;
			}

			if (self::cents($before['amount']) !== self::cents($line['amount'])) {
				$causes[] = self::cause(line: $line, delta: ['change' => 'changed', 'amountBefore' => $before['amount'], 'amountAfter' => $line['amount']]);
			}
		}

		return $causes;
	}//end arrivals()

	/**
	 * The earlier lines the later payslip no longer has.
	 *
	 * @param array<string, array<string, mixed>> $from The earlier lines by key.
	 * @param array<string, array<string, mixed>> $to   The later lines by key.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function departures(array $from, array $to): array {
		$causes = [];
		foreach (array_diff_key($from, $to) as $line) {
			$causes[] = self::cause(line: $line, delta: ['change' => 'gone', 'amountBefore' => $line['amount'], 'amountAfter' => null]);
		}

		return $causes;
	}//end departures()

	/**
	 * Lines keyed on (component, sourceSchema, sourceId); lines with the same
	 * key are summed.
	 *
	 * @param array<int, array<string, mixed>> $lines The lines.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function byKey(array $lines): array {
		$out = [];
		foreach ($lines as $line) {
			$key = implode("\x1f", [(string)($line['component'] ?? ''), (string)($line['sourceSchema'] ?? ''), (string)($line['sourceId'] ?? '')]);
			$amount = (float)($line['amount'] ?? 0.0);
			if (isset($out[$key]) === true) {
				$amount += $out[$key]['amount'];
			}

			$out[$key] = array_merge($line, ['amount' => round($amount, 2)]);
		}

		return $out;
	}//end byKey()

	/**
	 * One cause.
	 *
	 * @param array<string, mixed>                                                 $line  The line it is about.
	 * @param array{change: string, amountBefore: float|null, amountAfter: float|null} $delta `new`, `gone` or `changed`, and the earlier and later amount.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function cause(array $line, array $delta): array {
		return [
			'change'       => $delta['change'],
			'component'    => (string)($line['component'] ?? ''),
			'sourceSchema' => (($line['sourceSchema'] ?? null) !== null ? (string)$line['sourceSchema'] : null),
			'sourceId'     => (($line['sourceId'] ?? null) !== null ? (string)$line['sourceId'] : null),
			'sourceKind'   => (($line['sourceKind'] ?? null) !== null ? (string)$line['sourceKind'] : null),
			'amountBefore' => $delta['amountBefore'],
			'amountAfter'  => $delta['amountAfter'],
		];
	}//end cause()

	/**
	 * A euro amount as whole cents, for comparing.
	 *
	 * @param mixed $euros The amount.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function cents(mixed $euros): int {
		return (int)round(((float)$euros) * 100);
	}//end cents()
}//end class
