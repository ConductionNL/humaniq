<?php

/**
 * Calendar Target
 *
 * The resolved sync target of one calendar sync run: the duck-typed
 * CalDavBackend, the resolved calendar id, and the configured
 * principal/URI.
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
 * Immutable bundle of the resolved CalDAV backend + calendar + configured principal/URI.
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-001
 */
class CalendarTarget {

	/**
	 * @param mixed $backend The duck-typed CalDavBackend.
	 * @param mixed $calendarId The resolved calendar's id.
	 * @param string $principal The configured CalDAV principal.
	 * @param string $calendarUri The configured calendar URI.
	 */
	public function __construct(
		public readonly mixed $backend,
		public readonly mixed $calendarId,
		public readonly string $principal,
		public readonly string $calendarUri,
	) {

	}//end __construct()

}//end class
