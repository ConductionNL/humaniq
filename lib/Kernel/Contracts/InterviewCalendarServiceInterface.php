<?php

/**
 * Contract of InterviewCalendarService
 *
 * The public surface of InterviewCalendarService that other components call, so they depend
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
 * Contract of InterviewCalendarService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InterviewCalendarServiceInterface {
	/**
	 * Run the full sync: upsert one timed VEVENT per `scheduled` Interview,
	 * remove the event of any `cancelled` Interview, leave `completed`
	 * Interviews' events untouched, and reconcile orphaned
	 * `hrmq-interview-*.ics` events whose source was hard-deleted from the
	 * register (design.md D1-D6).
	 *
	 * @param string|null $from Optional ISO date (Y-m-d). Bounds the upsert
	 *                          set to Interviews whose scheduledStart is
	 *                          on/after this date; an out-of-bound
	 *                          Interview's already-synced event stays
	 *                          untouched. Reconciliation always uses the
	 *                          full live-id set regardless of `--from`.
	 *
	 * @return array<int, array<string, mixed>> One outcome row per touched
	 *                                          Interview, or a single
	 *                                          `skipped-no-calendar` row.
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-003
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-006
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-007
	 */
	public function sync(?string $from = null): array;

	/**
	 * Sync exactly one Interview (the guarded manifest action's single-object
	 * trigger, design.md D6): resolves the configured calendar, loads the
	 * one Interview by id, and returns its single outcome row.
	 *
	 * @param string $interviewId The Interview id.
	 *
	 * @return array<string, mixed> One outcome row.
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-008
	 */
	public function syncOne(string $interviewId): array;
}//end interface
