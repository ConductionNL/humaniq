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
			self::each(entries: (array)($slip['retroAdjustment']['sources'] ?? []), component: 'retroAdjustment', schema: 'PayrollAdjustment', kind: 'correction'),
			self::each(entries: (array)($slip['leaveSources'] ?? []), component: 'leaveBuySell', schema: 'LeaveTransaction', kind: 'leave-transaction'),
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
			return [self::source(component: 'hourlyPay', cents: $totalCents, schema: null, id: null, kind: null)];
		}

		$hours = array_sum(array_map(static fn (array $credit): float => (float)($credit['hours'] ?? 0.0), $credits));
		$left  = $totalCents;
		$out   = [];
		foreach ($credits as $index => $credit) {
			$cents = $left;
			if ($index < (count($credits) - 1)) {
				$share = 0.0;
				if ($hours > 0.0) {
					$share = ((float)($credit['hours'] ?? 0.0) / $hours);
				}

				$cents = (int)round($totalCents * $share);
			}

			$left -= $cents;
			$out[] = self::source(component: 'hourlyPay', cents: $cents, schema: 'Timesheet', id: (string)($credit['timesheetId'] ?? ''), kind: 'timesheet');
		}

		return $out;
	}//end hoursPay()

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

		return [self::source(component: 'sickPay', cents: $cents, schema: 'SickLeaveCase', id: RegisterRows::idOf($case), kind: 'case')];
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

		return [self::source(component: 'bijtelling', cents: (int)($slip['bijtellingCents'] ?? 0), schema: 'AssetAssignment', id: RegisterRows::idOf($assignment), kind: 'assignment')];
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

		return [self::source(component: 'loonbeslag', cents: (0 - $cents), schema: 'Loonbeslag', id: RegisterRows::idOf($loonbeslag), kind: 'garnishment')];
	}//end loonbeslag()

	/**
	 * One line per summed source of a fold.
	 *
	 * @param array<int, mixed> $entries   The fold's `{sourceId, cents}` entries.
	 * @param string            $component The payslip component.
	 * @param string            $schema    The sources' schema.
	 * @param string            $kind      The sources' kind.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function each(array $entries, string $component, string $schema, string $kind): array {
		$out = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$out[] = self::source(component: $component, cents: (int)($entry['cents'] ?? 0), schema: $schema, id: (string)($entry['sourceId'] ?? ''), kind: $kind);
		}

		return $out;
	}//end each()

	/**
	 * One source.
	 *
	 * @param string      $component The payslip component.
	 * @param int         $cents     The amount in cents.
	 * @param string|null $schema    The source's schema.
	 * @param string|null $id        The source's id.
	 * @param string|null $kind      The source's kind.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-mutation-lineage/design.md
	 */
	private static function source(string $component, int $cents, ?string $schema, ?string $id, ?string $kind): array {
		return [
			'component'    => $component,
			'amountCents'  => $cents,
			'sourceSchema' => $schema,
			'sourceId'     => ($id === '' ? null : $id),
			'sourceKind'   => $kind,
		];
	}//end source()
}//end class
