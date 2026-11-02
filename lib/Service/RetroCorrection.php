<?php

/**
 * Retro Correction
 *
 * One requested retro correction: the wage period, employee and caller
 * reference that identify it, and the corrected gross it is computed on.
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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One requested retro correction. The same period, employee and reference
 * always resolve to the same PayrollAdjustment.
 *
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
final class RetroCorrection {

	/**
	 * @param string     $originalPeriod              Wage period being corrected, `YYYY-MM`.
	 * @param string     $employeeId                  The Employee id.
	 * @param string     $correctionRef               Caller-supplied idempotency key.
	 * @param float|null $correctedGrossMonthlySalary Corrected gross monthly salary (euro); reused from an existing adjustment when null.
	 */
	public function __construct(
		public readonly string $originalPeriod,
		public readonly string $employeeId,
		public readonly string $correctionRef,
		public readonly ?float $correctedGrossMonthlySalary = null,
	) {

	}//end __construct()

	/**
	 * The same correction with the period, employee and reference trimmed.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 */
	public function trimmed(): self {
		return new self(trim($this->originalPeriod), trim($this->employeeId), trim($this->correctionRef), $this->correctedGrossMonthlySalary);
	}//end trimmed()

	/**
	 * The same correction with another corrected gross.
	 *
	 * @param float|null $gross The corrected gross monthly salary.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 */
	public function withGross(?float $gross): self {
		return new self($this->originalPeriod, $this->employeeId, $this->correctionRef, $gross);
	}//end withGross()

}//end class
