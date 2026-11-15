<?php

/**
 * Retro Recompute
 *
 * The independent recompute behind the `nl-retro-adjustment-consistency`
 * predicate: a PayrollAdjustment's corrected gross recomputed against its
 * recorded engine version, with the employee, contract and levy settings the
 * audit context carries, minus the original payslip's stored figures.
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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationResult;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\WageTaxElection;

/**
 * Recomputes one PayrollAdjustment against its audit context.
 *
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
final class RetroRecompute {

	/**
	 * @param array<string, mixed> $adjustment The PayrollAdjustment.
	 * @param array<string, mixed> $context    Evaluation context; reads `retro.*`.
	 */
	public function __construct(
		private readonly array $adjustment,
		private readonly array $context,
	) {

	}//end __construct()

	/**
	 * Whether a field the recompute depends on is blank or the corrected gross is not a number.
	 *
	 * @return bool
	 */
	public function lacksInput(): bool {
		return $this->field('originalPeriod') === ''
			|| $this->field('employeeId') === ''
			|| $this->field('originalPayslipId') === ''
			|| is_numeric($this->adjustment['correctedGrossMonthlySalary'] ?? null) === false;

	}//end lacksInput()

	/**
	 * Whether the original payslip and the employee both resolve in the context.
	 *
	 * @return bool
	 */
	public function isVerifiable(): bool {
		return is_array($this->storedPayslip()) === true && is_array($this->employee()) === true;

	}//end isVerifiable()

	/**
	 * Per component: the recomputed cents minus the original payslip's stored cents.
	 *
	 * Null when the engine version no longer resolves to a real table file.
	 *
	 * @param string $engineVersion The adjustment's recorded engine version.
	 *
	 * @return array<string, int>|null
	 */
	public function expectedDeltaCents(string $engineVersion): ?array {
		try {
			$tables = TaxTables::load($engineVersion);
		} catch (\Throwable $e) {
			return null;
		}

		$recomputed = self::componentCents((new PayrollCalculator())->calculate($this->input($tables), $tables));
		$stored     = (array)$this->storedPayslip();
		$expected   = [];
		foreach ($recomputed as $component => $cents) {
			$expected[$component] = ($cents - self::cents($stored, $component));
		}

		return $expected;

	}//end expectedDeltaCents()

	/**
	 * A field's value converted to integer cents (round-half-away-from-zero),
	 * defensively 0 for a missing/non-numeric value.
	 *
	 * @param array<string, mixed> $row Object.
	 * @param string               $key Field.
	 *
	 * @return int
	 */
	public static function cents(array $row, string $key): int {
		$value = ($row[$key] ?? null);
		return is_numeric($value) === true ? (int)round(((float)$value) * 100) : 0;

	}//end cents()

	/**
	 * The recomputed figures, in cents, by payslip component.
	 *
	 * @param CalculationResult $result The recompute.
	 *
	 * @return array<string, int>
	 */
	private static function componentCents(CalculationResult $result): array {
		return [
			'grossPay' => $result->grossPayCents,
			'loonheffing' => $result->loonheffingCents,
			'nettoPay' => $result->nettoPayCents,
			'werknemersverzekeringen' => $result->werknemersverzekeringenCents,
			'zvw' => $result->zvwCents,
			'volksverzekeringen' => $result->volksverzekeringenCents,
			'vakantiegeldReserved' => $result->vakantiegeldReservedCents,
		];

	}//end componentCents()

	/**
	 * The calculation input for the corrected gross.
	 *
	 * @param TaxTables $tables The engine version's tables.
	 *
	 * @return CalculationInput
	 */
	private function input(TaxTables $tables): CalculationInput {
		$employee = (array)$this->employee();

		return new CalculationInput(
			wage: $this->wage($employee),
			levies: new CalculationLevies(
				wageTax: self::wageTax($employee),
				insurance: $this->insurance($tables)
			)
		);

	}//end input()

	/**
	 * The corrected wage for the original period.
	 *
	 * @param array<string, mixed> $employee The employee row.
	 *
	 * @return CalculationWage
	 */
	private function wage(array $employee): CalculationWage {
		return new CalculationWage(
			grossMonthlySalaryCents: (int)round(((float)$this->adjustment['correctedGrossMonthlySalary']) * 100),
			dateOfBirth: (($employee['dateOfBirth'] ?? null) !== null ? (string)$employee['dateOfBirth'] : null),
			period: $this->field('originalPeriod')
		);

	}//end wage()

	/**
	 * The employee's wage-tax election, on the white table when no colour is set.
	 *
	 * @param array<string, mixed> $employee The employee row.
	 *
	 * @return WageTaxElection
	 */
	private static function wageTax(array $employee): WageTaxElection {
		return new WageTaxElection(
			taxTableColor: ((string)($employee['taxTableColor'] ?? '') !== '' ? (string)$employee['taxTableColor'] : 'wit'),
			loonheffingskortingToegepast: (($employee['loonheffingskortingToegepast'] ?? true) === true)
		);

	}//end wageTax()

	/**
	 * The insurance tariffs: the contract's Awf tariff, the context's Aof tariff and whk percentage.
	 *
	 * @param TaxTables $tables The engine version's tables, for the default whk percentage.
	 *
	 * @return InsuranceTariffs
	 */
	private function insurance(TaxTables $tables): InsuranceTariffs {
		$whkOverride   = ($this->context['retro']['whkPercentageOverride'] ?? null);
		$whkPercentage = is_numeric($whkOverride) === true ? (float)$whkOverride : (float)($tables->werknemersverzekeringen()['whkDefault'] ?? 0.0);

		return new InsuranceTariffs(
			awfTariff: self::awfTariffFor($this->context['retro']['contractsByEmployeeId'][$this->field('employeeId')] ?? null),
			aofTariff: (string)($this->context['retro']['aofTariff'] ?? 'laag'),
			whkPercentage: $whkPercentage
		);

	}//end insurance()

	/**
	 * The contract's Awf tariff (`low`/`high`), the PayrollRunService
	 * Wab-derived fallback precedent -- defaults to `high` when no contract
	 * resolves.
	 *
	 * @param array<string, mixed>|null $contract The employee's contract row, or null.
	 *
	 * @return string
	 */
	private static function awfTariffFor(?array $contract): string {
		if ($contract === null) {
			return 'high';
		}

		$tariff = trim((string)($contract['awfTariff'] ?? ''));
		if (in_array($tariff, ['low', 'high'], true) === true) {
			return $tariff;
		}

		$permanent = ((string)($contract['type'] ?? '') === 'permanent');
		$written   = (($contract['writtenContract'] ?? false) === true);
		return ($permanent === true && $written === true) ? 'low' : 'high';

	}//end awfTariffFor()

	/**
	 * The original payslip the adjustment corrects, or whatever the context holds for it.
	 *
	 * @return mixed
	 */
	private function storedPayslip(): mixed {
		return ($this->context['retro']['payslipsById'][$this->field('originalPayslipId')] ?? null);

	}//end storedPayslip()

	/**
	 * The adjustment's employee, or whatever the context holds for it.
	 *
	 * @return mixed
	 */
	private function employee(): mixed {
		return ($this->context['retro']['employeesById'][$this->field('employeeId')] ?? null);

	}//end employee()

	/**
	 * A trimmed string field of the adjustment.
	 *
	 * @param string $key Field.
	 *
	 * @return string
	 */
	private function field(string $key): string {
		return trim((string)($this->adjustment[$key] ?? ''));

	}//end field()
}//end class
