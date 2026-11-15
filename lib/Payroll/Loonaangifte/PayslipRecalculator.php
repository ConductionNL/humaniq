<?php

/**
 * PayslipRecalculator
 *
 * Recalculates a payslip with the payroll engine from the input stored on
 * it (`engineInputSnapshot`), the AwfReviewService precedent, so the wage
 * tax return can report the premium components the payslip stores only as
 * a roll-up (filings-wage-tax-message D5).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;


use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationResult;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use Psr\Log\LoggerInterface;

/**
 * The engine's recalculation of a stored payslip.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TaxTables::load and CalculationInput::fromDecoded are static by design, the AwfReviewService precedent.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
class PayslipRecalculator {

	/**
	 * The recalculator.
	 *
	 * @param PayrollCalculator    $calculator The payroll engine.
	 * @param LoggerInterface|null $logger     Logger for a payslip that cannot be recalculated.
	 * @param PackRepository       $packs      The jurisdiction-pack resolver.
	 */
	public function __construct(
		private readonly PayrollCalculator $calculator,
		private readonly ?LoggerInterface $logger=null,
		private readonly PackRepository $packs=new PackRepository(),
	) {
	}//end __construct()

	/**
	 * The engine's result for a payslip's stored input, or null when it has
	 * none or the engine cannot run it.
	 *
	 * @param array<string, mixed> $slip The payslip.
	 *
	 * @return CalculationResult|null
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public function recalculate(array $slip): ?CalculationResult {
		$snapshot = ($slip['engineInputSnapshot'] ?? null);
		if (is_array($snapshot) === false || $snapshot === []) {
			return null;
		}

		try {
			$tables = TaxTables::load($this->packs->resolve((string)($snapshot['jurisdiction'] ?? 'NL'), (string)($slip['period'] ?? ''))->tablesId());
			return $this->calculator->calculate(CalculationInput::fromDecoded($snapshot), $tables);
		} catch (\Throwable $e) {
			$this->logger?->warning('PayslipRecalculator: payslip ' . (string)($slip['id'] ?? '') . ' could not be recalculated: ' . $e->getMessage());
			return null;
		}
	}//end recalculate()

}//end class
