<?php

/**
 * Overlapping working pattern
 *
 * Raised when a second `WorkingPattern` would be written for an employee over
 * a period another pattern already covers.
 *
 * WHY THIS IS A REFUSAL AND NOT A PREFERENCE
 * ------------------------------------------
 * Two patterns covering one Tuesday give two answers to "how many hours does
 * this person work", and every caller that picks the first match picks a
 * different one depending on the order the object store happens to return.
 * Nothing looks wrong, the number is simply not the one the contract says. A
 * refused write is visible; a silently chosen answer is not.
 *
 * @category Exception
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
 * @spec openspec/specs/working-hours-per-person/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use RuntimeException;

/**
 * Thrown when two working patterns for one employee cover the same date.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
 */
class OverlappingWorkingPatternException extends RuntimeException {

}//end class
