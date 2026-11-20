<?php

/**
 * Contract of TimeEntryEventService
 *
 * The public surface of TimeEntryEventService that other components call, so they depend
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

/**
 * Contract of TimeEntryEventService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimeEntryEventServiceInterface {
	/**
	 * Slug of the schema whose approval emits the event (lower-cased for a
	 * case-insensitive compare against the resolved schema slug).
	 *
	 * @var string
	 */
	public const TIMESHEET_SLUG = 'timesheet';

	/**
	 * Emit the approved-time-entry CloudEvent iff this change is a Timesheet
	 * crossing into `approved`.
	 *
	 * Returns true only when an event was actually dispatched. All three gates
	 * (schema, transition, dispatch) must pass; each returns false silently
	 * otherwise so the listener stays a thin adapter.
	 *
	 * @param string $schemaSlug The slug of the changed object's schema.
	 * @param array<string, mixed>|null $oldData The object payload BEFORE the change (null on create).
	 * @param array<string, mixed> $newData The object payload AFTER the change.
	 *
	 * @return bool True when the CloudEvent was dispatched through the webhook.
	 *              The typed {@see TimesheetApprovedEvent} dispatch (ADR-041) is
	 *              always attempted alongside it, independently — its own
	 *              success/failure does not affect this return value, mirroring
	 *              the fire-and-forget contract of the webhook dispatch itself.
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function maybeDispatchApproved(string $schemaSlug, ?array $oldData, array $newData): bool;
}//end interface
