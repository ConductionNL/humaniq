<?php

/**
 * HR Document Request
 *
 * One request to HrDocumentService's shared generation pipeline.
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
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One request to generate an HR document: what it renders, its type, the
 * acting user and the extra keys of the 42-week notification.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */
final class HrDocumentRequest {

	/**
	 * @param HrDocumentSubject $subject The register objects the document renders.
	 * @param string $documentType The document type.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system'.
	 * @param array<string, mixed> $extra `sickLeaveCaseId` (the 42-week notification's key) and `adHocData` merged into the render data.
	 */
	public function __construct(
		public readonly HrDocumentSubject $subject,
		public readonly string $documentType,
		public readonly ?string $userId = null,
		public readonly array $extra = [],
	) {

	}//end __construct()

	/**
	 * The sickness case the document is keyed on, or null.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function sickLeaveCaseId(): mixed {
		return ($this->extra['sickLeaveCaseId'] ?? null);

	}//end sickLeaveCaseId()

	/**
	 * The identifying fields every GeneratedDocument of this request carries.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function documentFields(): array {
		return [
			'documentType' => $this->documentType,
			'employeeId' => $this->subject->employeeId,
			'contractId' => $this->subject->contractId,
			'payslipId' => $this->subject->payslipId,
			'jaaropgaafId' => $this->subject->jaaropgaafId,
			'sickLeaveCaseId' => $this->sickLeaveCaseId(),
		];

	}//end documentFields()

}//end class
