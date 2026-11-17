<?php

/**
 * Contract of TransitionPaymentService
 *
 * The public surface of TransitionPaymentService that other components call, so they depend
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
 * Contract of TransitionPaymentService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TransitionPaymentServiceInterface {
	/**
	 * Calculate the payment of a case and, when one is due, store it.
	 *
	 * @param string $offboardingId The case, already resolved under the caller's rights.
	 *
	 * @return array<string, mixed>|null The result, or null when the case does not exist.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
	 */
	public function calculateFor(string $offboardingId): ?array;
}//end interface
