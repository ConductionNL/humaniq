<?php

/**
 * Contract of HrDocumentSigningService
 *
 * The public surface of HrDocumentSigningService that other components call, so they depend
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
 * Contract of HrDocumentSigningService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HrDocumentSigningServiceInterface {
	/**
	 * Raise a signing request for one generated document.
	 *
	 * @param array<string, mixed> $document     The document, with its `id`.
	 * @param string               $actingUserId The HR user asking.
	 *
	 * @return array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-002
	 */
	public function requestSignature(array $document, string $actingUserId): array;

	/**
	 * Read the status of running requests and write it back.
	 *
	 * @param string|null $documentId One document, or null for every running request.
	 *
	 * @return list<array{status: string, message: string, signingRequestId: string|null, signingStatus: string|null}>
	 *
	 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-003
	 */
	public function syncSignatures(?string $documentId): array;
}//end interface
