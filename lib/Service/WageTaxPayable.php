<?php

/**
 * Wage Tax Payable
 *
 * Builds the draft shillinq APTransaction for a confirmed wage tax return
 * (payroll-wage-tax-remittance-shillinq D4). Pure: reads its arguments only.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The draft payable to the Belastingdienst for one return.
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */
class WageTaxPayable {

	/**
	 * @param float  $amount  The return's TotGen in euros.
	 * @param string $payeeId The shillinq payee.
	 * @param string $account The wage tax liability account.
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function __construct(
		private readonly float $amount,
		private readonly string $payeeId,
		private readonly string $account,
	) {
	}//end __construct()

	/**
	 * The draft APTransaction for a return (design D4).
	 *
	 * @param array<string, mixed> $run    The PayrollRun.
	 * @param array<string, mixed> $filing The confirmed return.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function build(array $run, array $filing): array {
		$period = (string)($filing['period'] ?? '');
		$number = (string)($filing['aangiftenummer'] ?? '');

		return [
			'vendorId' => $this->payeeId,
			'invoiceNumber' => trim((string)$filing['betalingskenmerk']),
			'invoiceReference' => ($number !== '' ? $number : null),
			'invoiceDate' => $this->lastDayOf(period: $period),
			'dueDate' => (string)($filing['deadline'] ?? ''),
			'currency' => 'EUR',
			'totalAmount' => $this->amount,
			'taxAmount' => 0.0,
			'lines' => [
				[
					'description' => 'Loonheffingen ' . $period . ($number !== '' ? ', aangifte ' . $number : ''),
					'accountNumber' => $this->account,
					'amount' => $this->amount,
				],
			],
			'state' => 'draft',
			'administrationId' => (string)($run['administrationId'] ?? ''),
		];
	}//end build()

	/**
	 * The last day of a YYYY-MM period (surrounding spaces allowed).
	 *
	 * @param string $period The period.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the period is not a YYYY-MM month: PHP would read '' or '202605' as today.
	 */
	private function lastDayOf(string $period): string {
		$period = trim($period);
		if (preg_match('/^\d{4}-(0?[1-9]|1[0-2])$/', $period) !== 1) {
			throw new InvalidArgumentException('The wage tax return has no period in YYYY-MM form, so the payable has no invoice date. Fix the period on the return.');
		}

		return (new DateTimeImmutable($period . '-01'))->format('Y-m-t');
	}//end lastDayOf()
}//end class
