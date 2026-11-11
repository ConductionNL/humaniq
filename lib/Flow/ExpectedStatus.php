<?php

/**
 * Humaniq ExpectedStatus
 *
 * The statuses a payroll service may report for a flow step to carry on, and
 * the words that open the refusal when it reports anything else.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

/**
 * Accepted statuses plus the refusal's opening words.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
 */
final class ExpectedStatus {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $accepted The statuses the step carries on with.
	 * @param string             $refusal  The refusal's opening words, before the status and the service message.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	public function __construct(
		public readonly array $accepted,
		public readonly string $refusal,
	) {

	}//end __construct()
}//end class
