<?php

/**
 * Contract of WorkingHoursService
 *
 * The public surface of WorkingHoursService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of WorkingHoursService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkingHoursServiceInterface {
	/**
	 * Refuse a set of patterns in which two cover the same employee on the same
	 * day.
	 *
	 * Call this before writing a pattern, with the candidate included in the
	 * set. The check is over the whole set rather than pairwise against the
	 * candidate, so an overlap already present is reported too.
	 *
	 * @param array<array<string, mixed>> $patterns WorkingPattern objects (plain arrays), one employee or many.
	 *
	 * @return void
	 *
	 * @throws \OCA\Humaniq\Service\OverlappingWorkingPatternException When two patterns for one employee overlap.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	public function assertPatternsDoNotOverlap(array $patterns): void;
}//end interface
