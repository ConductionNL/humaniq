<?php

/**
 * Contract of WorkingCalendarReader
 *
 * The public surface of WorkingCalendarReader that other components call, so they depend
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
 * Contract of WorkingCalendarReader.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkingCalendarReaderInterface {
	/**
	 * The dates openregister marks non-working inside a closed range.
	 *
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 *
	 * @return array{dates: array<int, string>|null, resolved: bool, reason: string|null}
	 *                                                                                   `dates` is null whenever `resolved` is false, so a caller cannot mistake an unread calendar for an empty one.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
	 */
	public function nonWorkingDates(DateTimeImmutable $from, DateTimeImmutable $to): array;
}//end interface
