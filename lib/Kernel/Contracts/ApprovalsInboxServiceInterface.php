<?php

/**
 * Contract of ApprovalsInboxService
 *
 * The public surface of ApprovalsInboxService that other components call, so they depend
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
 * Contract of ApprovalsInboxService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalsInboxServiceInterface {
	/**
	 * Every request waiting for this account today, oldest first.
	 *
	 * @param string $uid The caller.
	 * @param string $today The day, YYYY-MM-DD.
	 * @param string|null $kind Only this kind (leave, hours, expense, leave-trade).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function open(string $uid, string $today, ?string $kind = null): array;

	/**
	 * The requests this account decided in the last 90 days, newest first,
	 * each with its timeline.
	 *
	 * @param string $uid The caller.
	 * @param string $today The day, YYYY-MM-DD.
	 * @param string|null $kind Only this kind (leave, hours, expense, leave-trade).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 */
	public function decided(string $uid, string $today, ?string $kind = null): array;
}//end interface
