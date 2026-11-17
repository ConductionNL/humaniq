<?php

/**
 * Contract of AdministrationAccessTrust
 *
 * The public surface of AdministrationAccessTrust that other components call, so they depend
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
 * Contract of AdministrationAccessTrust.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdministrationAccessTrustInterface {
	/**
	 * The rows granted with standing, in their original order.
	 *
	 * A row is believed when its owner is an administrator, the system
	 * identity, or a user with a believed `hr` row for the same
	 * administration. The last rule is applied until nothing more is added,
	 * so a chain of HR grants that starts at an administrator holds.
	 *
	 * @param array<int, array<string, mixed>> $rows Every AdministrationAccess row, with `@self.owner`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function trusted(array $rows): array;

	/**
	 * Whether the user may grant or withdraw access to the administration:
	 * an administrator, or a user with a believed `hr` row for it.
	 *
	 * @param string                           $userId           The acting user.
	 * @param string                           $administrationId The administration the write is about.
	 * @param array<int, array<string, mixed>> $rows             Every AdministrationAccess row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function mayGrant(string $userId, string $administrationId, array $rows): bool;
}//end interface
