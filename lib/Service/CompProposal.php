<?php

/**
 * Humaniq CompProposal
 *
 * One employee's proposed adjustment in a bulk-proposal run.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * An employee and the CompAdjustment proposed for them.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
final class CompProposal {

	/**
	 * @param array<string, mixed> $employee The employee.
	 * @param array<string, mixed> $payload  The CompAdjustment payload.
	 */
	public function __construct(
		public readonly array $employee,
		public readonly array $payload,
	) {

	}//end __construct()
}//end class
