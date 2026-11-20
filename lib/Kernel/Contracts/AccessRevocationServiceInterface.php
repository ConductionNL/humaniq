<?php

/**
 * Contract of AccessRevocationService
 *
 * The public surface of AccessRevocationService that other components call, so they depend
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
 * Contract of AccessRevocationService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccessRevocationServiceInterface {
	/**
	 * Disable the leaver's account of one case and stamp the case. The caller
	 * has resolved the case under its own rights first.
	 *
	 * @param string $offboardingId The case.
	 * @param string $actorUid      Who revokes.
	 * @param string $now           The moment, ISO 8601.
	 *
	 * @return array{status: int, message: ?string, offboarding: ?array<string, mixed>} 200, or 404/409 with the reason.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function revoke(string $offboardingId, string $actorUid, string $now): array;

	/**
	 * Revoke every open case whose last working day has passed and whose
	 * access is not revoked yet. A refused case is logged and left.
	 *
	 * @param string $today The day, `YYYY-MM-DD`.
	 * @param string $now   The moment, ISO 8601.
	 *
	 * @return array<int, string> The cases revoked.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function revokeDue(string $today, string $now): array;
}//end interface
