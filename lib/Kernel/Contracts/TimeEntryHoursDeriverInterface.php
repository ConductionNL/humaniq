<?php

/**
 * Contract of TimeEntryHoursDeriver
 *
 * The public surface of TimeEntryHoursDeriver that other components call, so they depend
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
 * Contract of TimeEntryHoursDeriver.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimeEntryHoursDeriverInterface {
	/**
	 * The `origin` value that marks an entry as a running timer.
	 *
	 * Equal to `RunningTimerService::ORIGIN_TIMER` and to the enum member on
	 * `TimeEntry` in `lib/Settings/register.d/hr-timesheet.json`. All three name
	 * the same literal; a drift between them shows up as a timer that cannot be
	 * written rather than as an error anyone would read.
	 *
	 * @var string
	 */
	public const ORIGIN_TIMER = 'timer';

	/**
	 * Resolve the reference timestamp and the hours for a write.
	 *
	 * @param array<string, mixed>      $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored   The stored payload (update only).
	 *
	 * @return array{0: int, 1: float} The UTC reference timestamp and the hours.
	 *
	 * @throws \OCA\Humaniq\Listener\HoursWriteRefusedException When the booking is in neither shape,
	 *  or the shape it is in is impossible.
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-humaniq-captures-time-entries-under-a-submit-approve-lifecycle-req-tec-001
	 */
	public function derive(array $incoming, ?array $stored): array;

	/**
	 * Whether this write is a running timer rather than a booking missing its
	 * end.
	 *
	 * Public because the STAMP LISTENER needs the same answer. `origin` is
	 * `readOnly` on the schema, and a client value for it does not survive the
	 * ObjectService write path: an entry started with `origin: timer` came back
	 * stored as `manual`, the schema default. The marker therefore has to be
	 * stamped server-side like every other protected field, and the listener
	 * must not re-derive the rule from its own copy of these conditions.
	 *
	 * Reads the incoming `origin` first and the stored one second, so that
	 * stopping a timer, a write that carries an end and no origin, still sees
	 * the marker on the row it is closing.
	 *
	 * @param array<string, mixed>      $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored   The stored payload (update only).
	 *
	 * @return bool True when the write declares itself a timer.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function isRunningTimerWrite(array $incoming, ?array $stored): bool;
}//end interface
