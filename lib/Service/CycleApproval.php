<?php

/**
 * Humaniq CycleApproval
 *
 * The state of approving one compensation cycle: who approves, what the
 * approve transition carries, and the outcome so far.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One cycle being approved.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 */
final class CycleApproval {

	/**
	 * Who approves.
	 *
	 * @var string
	 */
	public string $callerUid = '';

	/**
	 * The inputs of every approve transition.
	 *
	 * @var array<string, string>
	 */
	public array $data = [];

	/**
	 * Counts and one row per proposed adjustment.
	 *
	 * @var array<string, mixed>
	 */
	public array $outcome = [];
}//end class
