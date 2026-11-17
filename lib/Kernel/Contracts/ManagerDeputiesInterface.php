<?php

/**
 * Contract of ManagerDeputies
 *
 * The public surface of ManagerDeputies that other components call, so they depend
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
 * Contract of ManagerDeputies.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ManagerDeputiesInterface {
	public const SLUG = 'managerdeputy';

	/**
	 * Everyone who may decide on a waiting request today: its managers and
	 * their active deputies, never the requester's own account.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function approversOf(array $request, string $today): array;

	/**
	 * Why a deputy record may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $record The record as it would be saved.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function refusal(array $record): ?string;

	/**
	 * Why this account may not write this deputy record, or null when it may.
	 *
	 * A manager names their own deputy; HR may name one for any manager, for
	 * instance for a manager who is off sick. A write without a user (an
	 * import, a repair step) is the system's own.
	 *
	 * @param string $writer The account saving the record, '' for the system.
	 * @param array<string, mixed> $record The record as it would be saved.
	 * @param array<string, mixed> $stored The record as stored, [] on create.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function authorityRefusal(string $writer, array $record, array $stored): ?string;
}//end interface
