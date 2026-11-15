<?php

/**
 * Formation Window
 *
 * The working days of one formation count, who is away on them, and the hours of one FTE.
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * The days one formation count averages over.
 *
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */
final class FormationWindow {

	/**
	 * @param list<DateTimeImmutable> $days              The working days of the window.
	 * @param FormationAbsence        $away              Who is away on which day.
	 * @param float                   $fullTimeHoursWeek Hours of one FTE.
	 */
	public function __construct(
		public readonly array $days,
		public readonly FormationAbsence $away,
		public readonly float $fullTimeHoursWeek,
	) {

	}//end __construct()

}//end class
