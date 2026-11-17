<?php

/**
 * Contract of ReceiptExtractionService
 *
 * The public surface of ReceiptExtractionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of ReceiptExtractionService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReceiptExtractionServiceInterface {
	/**
	 * The occ/controller backlog trigger (design.md D7): with `$expenseId`
	 * null, every Expense with a non-empty `receiptFile` and no active
	 * (`pending`/`extracted`) `ReceiptExtraction` is processed;
	 * `$expenseId` narrows to one Expense (its own `extractForExpense()`
	 * failure/idempotency semantics apply unchanged).
	 *
	 * @param string|null $expenseId Restrict to one Expense id, or null for the backlog.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<int, array<string, mixed>> One outcome array per attempt.
	 *
	 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-006
	 */
	public function backlog(?string $expenseId = null, ?string $userId = null): array;

	/**
	 * Extract one Expense's receipt: idempotency pre-check, duck-typed
	 * availability probe, the `extractFinancial()` call, the
	 * prefill-not-overwrite field mapping (design.md D4), and recording the
	 * attempt on a `ReceiptExtraction` at every step. Never throws.
	 *
	 * @param string $expenseId The Expense to extract for.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<string, mixed> Outcome: {expenseId, status, message, receiptExtractionId}.
	 *
	 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-002
	 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-003
	 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-004
	 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-005
	 */
	public function extractForExpense(string $expenseId, ?string $userId = null): array;
}//end interface
