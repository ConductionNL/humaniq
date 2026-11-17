<?php

/**
 * Contract of ReferralService
 *
 * The public surface of ReferralService that other components call, so they depend
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
 * Contract of ReferralService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReferralServiceInterface {
	/**
	 * Why a referral cannot be saved, or null when it can.
	 *
	 * @param array<string, mixed> $referral The referral as sent.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function refusal(array $referral): ?string;

	/**
	 * The fields the server sets on a new referral.
	 *
	 * @param array<string, mixed> $referral The referral as sent.
	 * @param string               $uid      The signed-in referrer.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function stamp(array $referral, string $uid): array;

	/**
	 * Create the application HR works with and link it on the referral.
	 *
	 * @param array<string, mixed> $referral   The stored referral.
	 * @param string               $referralId The referral's id.
	 *
	 * @return string The new application's id.
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function createApplication(array $referral, string $referralId): string;

	/**
	 * Carry an application's status onto the referral it came from.
	 *
	 * @param string $applicationId The application.
	 * @param string $status        Its status now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function followStatus(string $applicationId, string $status): void;
}//end interface
