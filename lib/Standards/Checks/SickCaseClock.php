<?php

/**
 * Humaniq SickCaseClock
 *
 * One sick leave case as the milestone derivation reads it: the case, its
 * first sick day, whether it is still open and the corpus's week offset per
 * milestone.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/specs/verzuim-wvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * One sick leave case as the milestone derivation reads it.
 *
 * @spec openspec/specs/verzuim-wvp/spec.md
 */
final class SickCaseClock {

	/**
	 * The SickLeaveCase.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * The first sick day, as a timestamp.
	 *
	 * @var integer
	 */
	public int $firstSickDay = 0;

	/**
	 * Whether the case is still open (gemeld).
	 *
	 * @var boolean
	 */
	public bool $isOpen = true;

	/**
	 * The corpus's week offset per milestone.
	 *
	 * @var array<string, mixed>
	 */
	public array $weeks = [];
}//end class
