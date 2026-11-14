<?php

/**
 * PensionBase
 *
 * The pension figures of one employee for one monthly period: the
 * part-time factor, the pensionable wage after the cap, the pension base
 * after the franchise and the employer's and employee's contributions
 * (filings-pension-upa-message D2).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Pension
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
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Pension;

/**
 * Pure arithmetic over a pay period's gross wage and a PensionScheme.
 *
 * A scheme states its franchise and maximum pensionable salary per year for
 * a full-time employee; a period is one month, so both are taken per month.
 * The cap applies to the wage, the franchise in proportion to the part-time
 * factor: base = max(0, min(wage, max) - franchise * partTimeFactor).
 *
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */
final class PensionBase {

	/**
	 * The periods of a year a scheme's yearly figures are spread over.
	 */
	public const PERIODS_PER_YEAR = 12;

	/**
	 * The pension figures of one employee for one month.
	 *
	 * @param float                $wage              The period's gross wage.
	 * @param float                $hoursPerWeek      The contract's hours per week.
	 * @param float                $fullTimeHoursWeek The hours per week of a full-time employee.
	 * @param array<string, mixed> $scheme            The PensionScheme.
	 *
	 * @return array{partTimeFactor: float, pensionableWage: float, pensionBase: float, employerContribution: float, employeeContribution: float}
	 *
	 * @throws \InvalidArgumentException When the hours or the full-time week are not positive.
	 *
	 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
	 */
	public static function of(float $wage, float $hoursPerWeek, float $fullTimeHoursWeek, array $scheme): array {
		if ($hoursPerWeek <= 0.0 || $fullTimeHoursWeek <= 0.0) {
			throw new \InvalidArgumentException('A part-time factor needs positive hours per week and a positive full-time week.');
		}

		$partTimeFactor = round(min(1.0, ($hoursPerWeek / $fullTimeHoursWeek)), 4);
		$maximum = (self::amount(scheme: $scheme, key: 'maxPensionableSalary') / self::PERIODS_PER_YEAR);
		$franchise = (self::amount(scheme: $scheme, key: 'franchise') / self::PERIODS_PER_YEAR);

		$pensionableWage = round(min(max(0.0, $wage), $maximum), 2);
		$pensionBase = round(max(0.0, ($pensionableWage - ($franchise * $partTimeFactor))), 2);

		return [
			'partTimeFactor' => $partTimeFactor,
			'pensionableWage' => $pensionableWage,
			'pensionBase' => $pensionBase,
			'employerContribution' => round(($pensionBase * self::amount(scheme: $scheme, key: 'employerPercentage') / 100), 2),
			'employeeContribution' => round(($pensionBase * self::amount(scheme: $scheme, key: 'employeePercentage') / 100), 2),
		];
	}//end of()

	/**
	 * A numeric figure of the scheme; absent or not a number counts as zero.
	 *
	 * @param array<string, mixed> $scheme The PensionScheme.
	 * @param string               $key    The figure.
	 *
	 * @return float
	 */
	private static function amount(array $scheme, string $key): float {
		$value = ($scheme[$key] ?? null);
		if (is_numeric($value) === false) {
			return 0.0;
		}

		return max(0.0, (float)$value);
	}//end amount()
}//end class
