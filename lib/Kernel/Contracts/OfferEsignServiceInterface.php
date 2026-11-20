<?php

/**
 * Contract of OfferEsignService
 *
 * The public surface of OfferEsignService that other components call, so they depend
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
 * Contract of OfferEsignService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OfferEsignServiceInterface {
	/**
	 * Generate the offer letter and raise a docudesk e-signature request for
	 * one Application (design.md D1-D7). Stage-guards to `aanbod`, applies
	 * the single-slot idempotency pre-check, duck-typed-degrades when
	 * docudesk is absent, and NEVER writes `Application.status` or invokes
	 * the `aannemen` transition.
	 *
	 * @param string $applicationId The Application id.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<string, mixed> Outcome: {applicationId, status, message, offerLetterFileId, offerSigningRequestId, offerSigningStatus}.
	 *
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-002
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-003
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-004
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-005
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-006
	 */
	public function requestSignature(string $applicationId, ?string $userId = null): array;

	/**
	 * Read-only poll of the docudesk signing-request status onto
	 * `Application.offerSigningStatus`. NEVER writes `Application.status` or
	 * invokes the `aannemen` transition (design.md D7, REQ-OFFR-006) --
	 * observing `COMPLETED` here is informational only. `getRequest()`
	 * carries no session guard, so this is genuinely CLI-safe (design.md D5
	 * point 3), unlike `requestSignature()`.
	 *
	 * @param string|null $applicationId One Application id, or null for the default scope: every Application whose `offerSigningRequestId` is set and `offerSigningStatus` is PENDING/IN_PROGRESS.
	 *
	 * @return array<int, array<string, mixed>> One outcome per polled Application.
	 *
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-006
	 */
	public function syncSignatureStatus(?string $applicationId = null): array;
}//end interface
