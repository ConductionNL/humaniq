<?php

/**
 * Humaniq PayAnomalyCheck
 *
 * The state of checking one payslip against the employee's own history
 * inside PayAnomalyDetector: the payslip, the baseline payslips, the
 * threshold overrides and the named cause, if any.
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One payslip being checked for deviations.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-003
 */
final class PayAnomalyCheck {

	/**
	 * The payslip.
	 *
	 * @var array<string, mixed>
	 */
	public array $payslip = [];

	/**
	 * The baseline payslips, most recent first.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $earlier = [];

	/**
	 * Threshold overrides per component.
	 *
	 * @var array<string, array{relative: float, absolute: float}>
	 */
	public array $thresholds = [];

	/**
	 * The causes that explain a deviation, or null when none does.
	 *
	 * @var string|null
	 */
	public ?string $explanation = null;
}//end class
