<?php

/**
 * Payslip Line Sources
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The sources of one calculated payslip, read from the slip the payroll run
 * just folded (payroll-mutation-lineage D1/D2): the salary or hours pay, the
 * sick-pay adjustment, the company-car bijtelling, each retro correction, each
 * leave transaction and the wage garnishment. It reads only what the run
 * already used, so the lines name exactly the sources the engine folded.
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
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Turns a folded slip into the sources of its payslip lines.
 *
 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md
 */
final class PayslipLineSources {

	/**
	 * The sources of a folded, calculated slip.
	 *
	 * @param array<string, mixed>      $slip         The slip as PayrollRunService folded it.
	 * @param array<string, mixed>|null $salarySource The salary's source (PayslipLineageService::salarySource()), null for an hourly employee.
	 *
	 * @return array<int, array<string, mixed>> Each `component`, `amountCents`, `sourceSchema`, `sourceId`, `sourceKind`.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md#requirement-each-calculated-payslip-shall-carry-one-line-per-component-with-its-source-req-pml-001
	 */
	public static function fromSlip(array $slip, ?array $salarySource): array {
		$sources = [];
		if ($salarySource !== null) {
			$sources[] = $salarySource;
		}

		$sources = array_merge(
			$sources,
			self::hoursPay(slip: $slip, salaried: ($salarySource !== null)),
			self::sickPay($slip),
			self::bijtelling($slip),
			self::each(entries: (array)($slip['retroAdjustment']['sources'] ?? []), ref: ['retroAdjustment', 'PayrollAdjustment', 'correction']),
			self::each(entries: (array)($slip['leaveSources'] ?? []), ref: ['leaveBuySell', 'LeaveTransaction', 'leave-transaction']),
			self::loonbeslag($slip)
		);

		return $sources;
	}//end fromSlip()

	/**
	 * An hourly employee's pay, one line per paid timesheet. The amounts are
	 * shared by hours and the last timesheet takes the rounding, so they add
	 * up to the hours pay on the payslip.
	 *
	 * @param array<string, mixed> $slip     The slip.
	 * @param bool                 $salaried Whether the salary line already covers the regular pay.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function hoursPay(array $slip, bool $salaried): array {
		$totalCents = (int)($slip['hoursPay']['hourlyCents'] ?? 0);
		$credits    = array_values((array)($slip['hoursPay']['timeCredits'] ?? []));
		if ($salaried === true || $totalCents === 0) {
			return [];
		}

		if ($credits === []) {
			return [self::source(cents: $totalCents, ref: ['hourlyPay', null, null, null])];
		}

		$out = [];
		foreach (self::shareByHours(totalCents: $totalCents, credits: $credits) as $index => $cents) {
			$out[] = self::source(cents: $cents, ref: ['hourlyPay', 'Timesheet', (string)($credits[$index]['timesheetId'] ?? ''), 'timesheet']);
		}

		return $out;
	}//end hoursPay()

	/**
	 * Share an amount over time credits by their hours; the last credit takes
	 * the rounding, so the shares add up to the amount.
	 *
	 * @param int                              $totalCents The amount, in cents.
	 * @param array<int, array<string, mixed>> $credits    The credits, a list.
	 *
	 * @return array<int, int> The cents per credit index.
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function shareByHours(int $totalCents, array $credits): array {
		$hours = array_sum(array_map(static fn (array $credit): float => (float)($credit['hours'] ?? 0.0), $credits));
		$left  = $totalCents;
		$last  = (count($credits) - 1);
		$out   = [];
		foreach ($credits as $index => $credit) {
			$cents = $left;
			if ($index < $last) {
				$cents = (int)round($totalCents * ($hours > 0.0 ? ((float)($credit['hours'] ?? 0.0) / $hours) : 0.0));
			}

			$left       -= $cents;
			$out[$index] = $cents;
		}

		return $out;
	}//end shareByHours()

	/**
	 * The difference the open sick case makes to the regular pay.
	 *
	 * @param array<string, mixed> $slip The slip.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function sickPay(array $slip): array {
		$case   = ($slip['sickCase'] ?? null);
		$result = ($slip['sickResult'] ?? null);
		if (is_array($case) === false || is_object($result) === false) {
			return [];
		}

		$cents = ((int)$result->payableGrossCents - (int)($slip['regularWageCents'] ?? 0));

		return [self::source(cents: $cents, ref: ['sickPay', 'SickLeaveCase', RegisterRows::idOf($case), 'case'])];
	}//end sickPay()

	/**
	 * The company car's bijtelling.
	 *
	 * @param array<string, mixed> $slip The slip.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function bijtelling(array $slip): array {
		$assignment = ($slip['assetAssignment'] ?? null);
		if (is_array($assignment) === false) {
			return [];
		}

		return [self::source(cents: (int)($slip['bijtellingCents'] ?? 0), ref: ['bijtelling', 'AssetAssignment', RegisterRows::idOf($assignment), 'assignment'])];
	}//end bijtelling()

	/**
	 * The wage garnishment deducted, as a negative amount.
	 *
	 * @param array<string, mixed> $slip The slip.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function loonbeslag(array $slip): array {
		$loonbeslag = ($slip['loonbeslag'] ?? null);
		$cents      = (int)($slip['loonbeslagDeductionCents'] ?? 0);
		if (is_array($loonbeslag) === false || $cents === 0) {
			return [];
		}

		return [self::source(cents: (0 - $cents), ref: ['loonbeslag', 'Loonbeslag', RegisterRows::idOf($loonbeslag), 'garnishment'])];
	}//end loonbeslag()

	/**
	 * One line per summed source of a fold.
	 *
	 * @param array<int, mixed>   $entries The fold's `{sourceId, cents}` entries.
	 * @param array<int, string> $ref     The payslip component, the sources' schema and their kind.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function each(array $entries, array $ref): array {
		[$component, $schema, $kind] = $ref;
		$out = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$out[] = self::source(cents: (int)($entry['cents'] ?? 0), ref: [$component, $schema, (string)($entry['sourceId'] ?? ''), $kind]);
		}

		return $out;
	}//end each()

	/**
	 * One source.
	 *
	 * @param int                     $cents The amount in cents.
	 * @param array<int, string|null> $ref   The payslip component, then the source's schema, id and kind (each null when none).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function source(int $cents, array $ref): array {
		[$component, $schema, $id, $kind] = $ref;

		return [
			'component'    => $component,
			'amountCents'  => $cents,
			'sourceSchema' => $schema,
			'sourceId'     => ($id === '' ? null : $id),
			'sourceKind'   => $kind,
		];
	}//end source()
}//end class
