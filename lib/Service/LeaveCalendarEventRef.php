<?php

/**
 * Leave Calendar Event Ref
 *
 * Names the calendar event of one LeaveRequest or SickLeaveCase: its
 * source type and id, and the deterministic UID and object URI derived
 * from them.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The source type and id of one leave calendar event.
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */
final class LeaveCalendarEventRef {

	/**
	 * Object-URI/UID prefix for LeaveRequest-derived events (design.md D2).
	 *
	 * @var string
	 */
	// FROZEN at the old app id: already-synced CalDAV events carry this URI
	// prefix. Renaming it would double-create every leave event and orphan the
	// existing ones beyond the reconciler's reach.
	public const LEAVE_URI_PREFIX = 'hrmq-leave-';

	/**
	 * Object-URI/UID prefix for SickLeaveCase-derived events (design.md D2).
	 *
	 * @var string
	 */
	// FROZEN at the old app id — same reason as LEAVE_URI_PREFIX above.
	public const SICK_URI_PREFIX = 'hrmq-sick-';

	/**
	 * @param string $type `leave` or `sick`.
	 * @param string $sourceId The source object id.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $sourceId,
	) {

	}//end __construct()

	/**
	 * @return string The deterministic VEVENT UID.
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 */
	public function uid(): string {
		$prefix = ($this->type === 'leave') ? self::LEAVE_URI_PREFIX : self::SICK_URI_PREFIX;
		return $prefix . $this->sourceId;

	}//end uid()

	/**
	 * @return string The deterministic object URI (`{uid}.ics`).
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
	 */
	public function objectUri(): string {
		return $this->uid() . '.ics';

	}//end objectUri()

}//end class
