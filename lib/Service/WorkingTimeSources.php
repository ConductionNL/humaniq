<?php

/**
 * Working Time Sources
 *
 * The working patterns, non-working times and working-calendar dates an
 * availability is measured on.
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
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The records that say when people work, as the caller fetched them.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */
final class WorkingTimeSources {

	/**
	 * @param array<array<string, mixed>> $workingPatterns WorkingPattern objects.
	 * @param array<array<string, mixed>> $nonWorkingTimes NonWorkingTime objects.
	 * @param array<int, string>|null $nonWorkingDates ISO dates the working calendar marks non-working, or null when it could not be read.
	 */
	public function __construct(
		public readonly array $workingPatterns = [],
		public readonly array $nonWorkingTimes = [],
		public readonly ?array $nonWorkingDates = null,
	) {

	}//end __construct()

}//end class
