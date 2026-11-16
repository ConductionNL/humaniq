<?php

/**
 * Contract of LeaveBuySellSettlementService
 *
 * The public surface of LeaveBuySellSettlementService that other components call, so they depend
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
 * Contract of LeaveBuySellSettlementService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaveBuySellSettlementServiceInterface {
	/**
	 * Settle one LeaveTransaction by id — the guarded endpoint's/occ
	 * command's entry point (design.md D4). The controller/command has
	 * already RBAC-resolved (or otherwise obtained) the transaction; this
	 * re-fetches it and applies the full approved+settlementPeriod+
	 * sufficiency predicate before writing.
	 *
	 * @param string $transactionId The LeaveTransaction id.
	 *
	 * @return array<string, mixed> Outcome: {transactionId, status, message, settledAmount?}.
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-002
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
	 */
	public function settle(string $transactionId): array;
}//end interface
