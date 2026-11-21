<?php

/**
 * Expense VAT Service
 *
 * The VAT to reclaim per period (REQ-RCPT-008): every claim of the caller's
 * active administration reimbursed in the month, through payroll (its
 * `paidInPeriod`) or directly (its `reimbursedAt`), that records a VAT
 * amount, and their total. HR and the accountant role read it; anyone else
 * is refused (null).
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;

/**
 * The reimbursed claims with VAT of one month, and their total.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md#requirement-a-claim-shall-record-its-vat-and-finance-shall-see-the-vat-to-reclaim-per-period-req-rcpt-008
 */
final class ExpenseVatService {

	/**
	 * Constructor.
	 *
	 * @param AnalyticsService $analytics Reads the administration's claims.
	 * @param AnalyticsAccess  $access    Who may read the administration's figures.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md#requirement-a-claim-shall-record-its-vat-and-finance-shall-see-the-vat-to-reclaim-per-period-req-rcpt-008
	 */
	public function __construct(
		private readonly AnalyticsService $analytics,
		private readonly AnalyticsAccess $access,
	) {

	}//end __construct()

	/**
	 * The VAT overview of the month, or null when the caller may not read it.
	 *
	 * @param string $userId The caller.
	 * @param string $period The month, `YYYY-MM`.
	 *
	 * @return array{period: string, administrationId: string, count: int, totalVat: float, claims: list<array<string, mixed>>}|null
	 *
	 * @throws InvalidArgumentException For a period that is not `YYYY-MM`.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md#requirement-a-claim-shall-record-its-vat-and-finance-shall-see-the-vat-to-reclaim-per-period-req-rcpt-008
	 */
	public function overview(string $userId, string $period): ?array {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			throw new InvalidArgumentException('Invalid period');
		}

		$administrationId = $this->access->fullReaderAdministration($userId);
		if ($administrationId === null) {
			return null;
		}

		$claims = [];
		$cents = 0;
		foreach ($this->analytics->rowsFor('Expense', $administrationId) as $claim) {
			$vat = ($claim['vatAmount'] ?? null);
			if (is_numeric($vat) === false || (float)$vat <= 0.0 || self::reimbursedIn(claim: $claim, period: $period) === false) {
				continue;
			}

			$cents += (int)round(((float)$vat) * 100);
			$claims[] = [
				'id' => (string)($claim['id'] ?? $claim['@self']['id'] ?? ''),
				'title' => (string)($claim['title'] ?? ''),
				'vendor' => (string)($claim['vendor'] ?? ''),
				'employeeId' => (string)($claim['employeeId'] ?? ''),
				'expenseDate' => (string)($claim['expenseDate'] ?? ''),
				'amount' => round((float)($claim['amount'] ?? 0), 2),
				'vatAmount' => round((float)$vat, 2),
			];
		}

		usort($claims, static fn (array $a, array $b): int => [$a['expenseDate'], $a['id']] <=> [$b['expenseDate'], $b['id']]);

		return ['period' => $period, 'administrationId' => $administrationId, 'count' => count($claims), 'totalVat' => round(($cents / 100), 2), 'claims' => $claims];
	}//end overview()

	/**
	 * Whether the claim was reimbursed in the month: paid on that month's
	 * payslip, or reimbursed directly in it.
	 *
	 * @param array<string, mixed> $claim  The Expense.
	 * @param string               $period The month.
	 *
	 * @return bool
	 */
	private static function reimbursedIn(array $claim, string $period): bool {
		$paidIn = trim((string)($claim['paidInPeriod'] ?? ''));
		if ($paidIn !== '') {
			return $paidIn === $period;
		}

		return substr(trim((string)($claim['reimbursedAt'] ?? '')), 0, 7) === $period;
	}//end reimbursedIn()
}//end class
