<?php

/**
 * Humaniq HrSignatureRequest
 *
 * One signing request being raised inside HrDocumentSigningService: the
 * generated document, the HR user asking, and the employee it is about.
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
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The signing request of one generated document.
 *
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-002
 */
final class HrSignatureRequest {

	/**
	 * The generated document, with its `id`.
	 *
	 * @var array<string, mixed>
	 */
	public array $document = [];

	/**
	 * The HR user asking.
	 *
	 * @var string
	 */
	public string $actingUserId = '';

	/**
	 * The employee the document is about ([] when unknown).
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];
}//end class
