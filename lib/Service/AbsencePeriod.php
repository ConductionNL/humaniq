<?php

/**
 * Absence Period
 *
 * The closed period an absence rate is measured over, with the hours a
 * full-time week holds.
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
 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * A closed measurement period and its full-time week.
 *
 * @spec openspec/specs/absence-rate/spec.md#REQ-ABSRATE-001
 */
final class AbsencePeriod {

	/**
	 * @param DateTimeImmutable $start First day of the period, inclusive.
	 * @param DateTimeImmutable $end Last day of the period, inclusive.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 */
	public function __construct(
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
		public readonly float $fullTimeHoursWeek = AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
	) {

	}//end __construct()

}//end class
