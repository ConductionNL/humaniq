<?php

/**
 * Band Op
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `band(value, table, unit)` (payroll-reservation-payouts design.md D2).
 *
 * Returns the `percentage` of the first row whose `tot` reaches or exceeds
 * `value`; a `null` `tot` matches unconditionally (the open top band). Unlike
 * `bracket` it applies nothing to the value: the special-rate table
 * (tabel bijzondere beloningen) selects a percentage by the annual wage, and
 * the pack multiplies the one-off payment by it in a later step.
 *
 * `unit` is declared, never assumed, for the same reason as `bracket`: the
 * corpus carries no unit marker, and NL's `tot` values are euros.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Dsl\Ops
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\StepContext;

/**
 * Selects a band's percentage by a threshold value.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
 */
final class BandOp extends AbstractOp {

	/**
	 * The units a table's `tot` values may be declared in.
	 *
	 * @var array<int, string>
	 */
	public const UNITS = ['euro', 'cent'];

	/**
	 * The op's pack name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	public function name(): string {
		return 'band';
	}//end name()

	/**
	 * The percentage of the band the value falls in.
	 *
	 * @param array<string, mixed> $spec The step spec: `value`, `table`, `unit`.
	 * @param StepContext          $ctx  The step context.
	 *
	 * @return mixed The percentage.
	 *
	 * @throws DslException When the unit, the table or a row is malformed.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	public function evaluate(array $spec, StepContext $ctx): mixed {
		$unit = (string)($spec['unit'] ?? '');
		if (in_array($unit, self::UNITS, true) === false) {
			throw new DslException('Pack: op "band" verwacht een gedeclareerde "unit" (' . implode('|', self::UNITS) . '); de tabelcorpus draagt zelf geen eenheid.');
		}

		$value = $this->num($spec, 'value', $ctx);
		$rows = $this->refs->value(($spec['table'] ?? null), $ctx);
		if (is_array($rows) === false || $rows === []) {
			throw new DslException('Pack: op "band" verwacht een niet-lege rijenlijst in "table".');
		}

		$reaches = function (mixed $tot) use ($value, $unit, $ctx): bool {
			$limit = ($unit === 'cent') ? (int)$tot : $ctx->tables()->toCents((float)$tot);
			return $value <= $limit;
		};
		$row = $this->select(rows: $rows, reaches: $reaches);
		if (is_numeric($row['percentage'] ?? null) === false) {
			throw new DslException('Pack: op "band" verwacht een numeriek "percentage" in elke rij.');
		}

		return (float)$row['percentage'];
	}//end evaluate()

	/**
	 * The first row whose `tot` reaches the value; the last row when none does.
	 *
	 * A row without `tot` is open-ended and always selected.
	 *
	 * @param array<int, mixed>       $rows    The table rows.
	 * @param callable(mixed): bool   $reaches Whether a `tot` limit reaches the value.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @throws DslException When a row is not an object.
	 */
	private function select(array $rows, callable $reaches): array {
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				throw new DslException('Pack: op "band" verwacht rijen met "tot" en "percentage".');
			}

			if (($row['tot'] ?? null) === null || $reaches($row['tot']) === true) {
				return $row;
			}
		}

		return end($rows);
	}//end select()
}//end class
