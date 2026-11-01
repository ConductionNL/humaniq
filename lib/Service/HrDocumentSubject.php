<?php

/**
 * HR Document Subject
 *
 * The register objects one generated HR document renders: the employee,
 * and optionally the contract, payslip or jaaropgaaf.
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
 * The register objects one generated HR document renders.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */
final class HrDocumentSubject {

	/**
	 * @param string $employeeId The Employee the document is for.
	 * @param string|null $contractId The EmploymentContract it evidences, or null.
	 * @param string|null $payslipId The Payslip a loonstrook renders, or null.
	 * @param string|null $jaaropgaafId The Jaaropgaaf a jaaropgaaf renders, or null.
	 */
	public function __construct(
		public readonly string $employeeId,
		public readonly ?string $contractId = null,
		public readonly ?string $payslipId = null,
		public readonly ?string $jaaropgaafId = null,
	) {

	}//end __construct()

	/**
	 * The same subject with the employee id trimmed and a blank contract id as null.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function normalised(): self {
		$contractId = ($this->contractId !== null && trim($this->contractId) !== '') ? trim($this->contractId) : null;
		return new self(trim($this->employeeId), $contractId, $this->payslipId, $this->jaaropgaafId);

	}//end normalised()

}//end class
