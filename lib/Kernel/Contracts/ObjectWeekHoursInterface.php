<?php

/**
 * Contract of ObjectWeekHours
 *
 * The public surface of ObjectWeekHours that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ObjectWeekHours.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectWeekHoursInterface {
	/**
	 * The week holding `$day`, Monday to Sunday, on one object.
	 *
	 * @param string            $domainObjectType The `<app>:<schema>` literal of the object.
	 * @param string            $domainObjectRef  The object's uuid.
	 * @param DateTimeImmutable $day              Any day of the week.
	 *
	 * @return array<string, mixed> `week`, `weekStart`, `weekEnd`, `total` and `people` (most hours first).
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
	 */
	public function forObject(string $domainObjectType, string $domainObjectRef, DateTimeImmutable $day): array;
}//end interface
