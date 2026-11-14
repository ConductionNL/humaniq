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

		$causes = [];
		foreach ($to as $key => $line) {
			$before = ($from[$key] ?? null);
			if ($before === null) {
				$causes[] = self::cause(change: 'new', line: $line, before: null, after: $line['amount']);
				continue;
			}

			if (self::cents($before['amount']) !== self::cents($line['amount'])) {
				$causes[] = self::cause(change: 'changed', line: $line, before: $before['amount'], after: $line['amount']);
			}
		}

		foreach ($from as $key => $line) {
			if (isset($to[$key]) === false) {
				$causes[] = self::cause(change: 'gone', line: $line, before: $line['amount'], after: null);
			}
		}

		return $causes;
	}//end between()

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
	 * @param string               $change `new`, `gone` or `changed`.
	 * @param array<string, mixed> $line   The line it is about.
	 * @param float|null           $before The earlier amount.
	 * @param float|null           $after  The later amount.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function cause(string $change, array $line, ?float $before, ?float $after): array {
		return [
			'change'       => $change,
			'component'    => (string)($line['component'] ?? ''),
			'sourceSchema' => (($line['sourceSchema'] ?? null) !== null ? (string)$line['sourceSchema'] : null),
			'sourceId'     => (($line['sourceId'] ?? null) !== null ? (string)$line['sourceId'] : null),
			'sourceKind'   => (($line['sourceKind'] ?? null) !== null ? (string)$line['sourceKind'] : null),
			'amountBefore' => $before,
			'amountAfter'  => $after,
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
