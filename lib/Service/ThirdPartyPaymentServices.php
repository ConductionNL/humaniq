<?php

/**
 * Third Party Payment Services
 *
 * The two services behind the third-party payments endpoints: one assembles
 * the yearly report, the other generates a payee's statement from it.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The services behind one controller.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
final class ThirdPartyPaymentServices {

	/**
	 * @param ThirdPartyReportService $reports Assembles the report.
	 * @param ThirdPartyStatementService $statements Generates the statement.
	 */
	public function __construct(
		public readonly ThirdPartyReportService $reports,
		public readonly ThirdPartyStatementService $statements,
	) {

	}//end __construct()
}//end class
