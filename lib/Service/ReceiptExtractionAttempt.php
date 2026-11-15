<?php

/**
 * Receipt Extraction Attempt
 *
 * One attempt to extract an Expense's receipt inside ReceiptExtractionService:
 * the Expense id and the acting user, the Expense and its receipt file once
 * loaded, and the ReceiptExtraction row once created.
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
 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The extraction attempt for one Expense.
 *
 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-002
 */
final class ReceiptExtractionAttempt {

	/**
	 * The Expense id, trimmed.
	 *
	 * @var string
	 */
	public string $expenseId = '';

	/**
	 * The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The current Expense (pre-write), once loaded.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $expense = null;

	/**
	 * The Expense's non-empty receiptFile value, once read.
	 *
	 * @var string
	 */
	public string $receiptFile = '';

	/**
	 * The ReceiptExtraction row of this attempt, once created.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];
}//end class
