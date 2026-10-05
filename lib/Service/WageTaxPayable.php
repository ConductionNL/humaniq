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

/**
 * The draft payable to the Belastingdienst for one return.
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
 */
class WageTaxPayable {

	/**
	 * The draft APTransaction for a return (design D4).
	 *
	 * @param array<string, mixed> $run     The PayrollRun.
	 * @param array<string, mixed> $filing  The confirmed return.
	 * @param float                $amount  The return's TotGen in euros.
	 * @param string               $payeeId The shillinq payee.
	 * @param string               $account The wage tax liability account.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function build(array $run, array $filing, float $amount, string $payeeId, string $account): array {
		$period = (string)($filing['period'] ?? '');
		$number = (string)($filing['aangiftenummer'] ?? '');

		return [
			'vendorId' => $payeeId,
			'invoiceNumber' => trim((string)$filing['betalingskenmerk']),
			'invoiceReference' => ($number !== '' ? $number : null),
			'invoiceDate' => $this->lastDayOf(period: $period),
			'dueDate' => (string)($filing['deadline'] ?? ''),
			'currency' => 'EUR',
			'totalAmount' => $amount,
			'taxAmount' => 0.0,
			'lines' => [
				[
					'description' => 'Loonheffingen ' . $period . ($number !== '' ? ', aangifte ' . $number : ''),
					'accountNumber' => $account,
					'amount' => $amount,
				],
			],
			'state' => 'draft',
			'administrationId' => (string)($run['administrationId'] ?? ''),
		];
	}//end build()

	/**
	 * The last day of a YYYY-MM period.
	 *
	 * @param string $period The period.
	 *
	 * @return string
	 */
	private function lastDayOf(string $period): string {
		try {
			$first = new DateTimeImmutable($period . '-01');
		} catch (\Exception $e) {
			return $period;
		}

		return $first->format('Y-m-t');
	}//end lastDayOf()
}//end class
