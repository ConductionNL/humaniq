<?php

/**
 * Payroll Run Services
 *
 * The payroll run services behind the payroll endpoints: recalculating a run,
 * diffing and persisting its mutations, simulating a pro-forma payslip and
 * settling a retro adjustment.
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The services behind one controller.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-008
 */
final class PayrollRunServices {

	/**
	 * @param PayrollRunService $payrollRunService The draft-run generation service.
	 * @param PayrollMutationService $payrollMutationService The run-to-run diff service.
	 * @param ProformaPayslipService $proformaPayslipService The persist-nothing pro-forma simulation service.
	 * @param RetroAdjustmentService $retroAdjustmentService The TWK delta computation + settlement service.
	 */
	public function __construct(
		public readonly PayrollRunService $payrollRunService,
		public readonly PayrollMutationService $payrollMutationService,
		public readonly ProformaPayslipService $proformaPayslipService,
		public readonly RetroAdjustmentService $retroAdjustmentService,
	) {

	}//end __construct()
}//end class
