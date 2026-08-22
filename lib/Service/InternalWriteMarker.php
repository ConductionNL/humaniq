<?php

/**
 * Humaniq InternalWriteMarker
 *
 * Request-scoped marker distinguishing humaniq's OWN internal register writes
 * (the aggregate recompute of TimesheetAggregationService, the
 * MigrateHoursProcess repair step) from client-originated writes, so the
 * pre-save listeners can exempt them (hours-process-redesign Decisions 3/4):
 * without the marker the stamping listener would sanitise the aggregation
 * write's own values into a no-op, and the mutability guard would refuse the
 * migration's synthetic entries for approved timesheets.
 *
 * A service-level flag — registered as a shared service in the app container,
 * set and reset strictly around the internal ObjectService call via
 * {@see runInternal()} — NEVER a global or a static, per the design's
 * explicit rule. The try/finally in runInternal() guarantees the marker can
 * not leak past an exception and silently exempt a later client write in the
 * same request.
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Request-scoped internal-writer marker for humaniq's own register writes.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */
class InternalWriteMarker {

	/**
	 * Nesting depth — an int rather than a bool so an internal write that
	 * itself triggers another internal write (aggregate recompute inside the
	 * repair step) cannot clear the marker early on its inner exit.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Whether the current call stack is inside an internal humaniq write.
	 *
	 * @return bool True inside {@see runInternal()}.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	public function isInternal(): bool {
		return $this->depth > 0;
	}//end isInternal()

	/**
	 * Run a callable with the marker set, resetting it afterwards — even
	 * when the callable throws.
	 *
	 * @param callable $write The internal write to perform.
	 *
	 * @return mixed The callable's return value.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	public function runInternal(callable $write): mixed {
		++$this->depth;
		try {
			return $write();
		} finally {
			--$this->depth;
		}
	}//end runInternal()

}//end class
