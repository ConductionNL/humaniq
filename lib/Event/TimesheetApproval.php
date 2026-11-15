<?php

/**
 * Timesheet Approval
 *
 * Who approved a timesheet and when.
 *
 * @category Event
 * @package  OCA\Humaniq\Event
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
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */

declare(strict_types=1);

namespace OCA\Humaniq\Event;

/**
 * Part of a {@see TimesheetApprovedEvent}.
 *
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */
final class TimesheetApproval {

	/**
	 * @param string $approvedBy Nextcloud user id of the approving manager.
	 * @param string $approvedAt ISO 8601 UTC approval timestamp.
	 */
	public function __construct(
		public readonly string $approvedBy,
		public readonly string $approvedAt,
	) {

	}//end __construct()
}//end class
