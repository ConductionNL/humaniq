<?php

/**
 * Humaniq CompProposalRun
 *
 * The state of one bulk-proposal run over a compensation cycle: the cycle,
 * who proposes, whether it is a dry run, what exists, and the outcome.
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
 * One run of bulk proposals.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
final class CompProposalRun {

	/**
	 * The CompReviewCycle id.
	 *
	 * @var string
	 */
	public string $cycleId = '';

	/**
	 * The cycle.
	 *
	 * @var array<string, mixed>
	 */
	public array $cycle = [];

	/**
	 * Who proposes; stamped as proposedBy.
	 *
	 * @var string
	 */
	public string $callerUid = '';

	/**
	 * Count and list, write nothing.
	 *
	 * @var bool
	 */
	public bool $dryRun = false;

	/**
	 * Hand-picked employee ids, or empty for the cycle's scope.
	 *
	 * @var array<int, string>
	 */
	public array $selection = [];

	/**
	 * Employees that already have an adjustment in the cycle, as keys.
	 *
	 * @var array<string, bool>
	 */
	public array $existing = [];

	/**
	 * Contracts by employee id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $contracts = [];

	/**
	 * The outcome so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $outcome = [];
}//end class
