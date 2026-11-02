<?php

/**
 * Payroll Tax Filing Services
 *
 * The two services behind the wage tax filing endpoints: one builds and sends
 * the period messages, the other the corrections of earlier periods.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The services behind one controller.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
final class PayrollTaxFilingServices {

	/**
	 * @param LoonaangifteMessageService $messages The message service.
	 * @param LoonaangifteCorrectionService $corrections The correction service.
	 */
	public function __construct(
		public readonly LoonaangifteMessageService $messages,
		public readonly LoonaangifteCorrectionService $corrections,
	) {

	}//end __construct()
}//end class
