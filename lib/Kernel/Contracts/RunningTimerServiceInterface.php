<?php

/**
 * Contract of RunningTimerService
 *
 * The public surface of RunningTimerService that other components call, so they depend
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
 * Contract of RunningTimerService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RunningTimerServiceInterface {
	/**
	 * Outcome: the caller already had a timer running, so nothing was started.
	 *
	 * @var string
	 */
	public const STATUS_ALREADY_RUNNING = 'already-running';

	/**
	 * Outcome: the request could not be acted on as sent.
	 *
	 * @var string
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * Outcome: no timer is running for this caller.
	 *
	 * @var string
	 */
	public const STATUS_NONE = 'none';

	/**
	 * Outcome: a timer is now running, or was found running.
	 *
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * The caller's running timer entry, or null.
	 *
	 * Filters on the caller and the timer marker in the query, then keeps only
	 * the rows with no end. "No end" is checked in PHP on purpose: OpenRegister
	 * has no filter grammar for "this property is absent", and asking it for a
	 * null would filter on the literal instead.
	 *
	 * @param string $uid The calling user's Nextcloud id.
	 *
	 * @return array<string, mixed>|null The running entry, or null.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	public function running(string $uid): ?array;

	/**
	 * Start a timer against a host object.
	 *
	 * Writes only what an integration knows: the moment, the marker, and the
	 * host object's reference. Everything else on the entry, including the
	 * employee, the user and the parent timesheet, is stamped by
	 * {@see \OCA\Humaniq\Listener\TimeEntryStampListener} exactly as it is for a
	 * booking typed by hand.
	 *
	 * @param string $uid               The calling user's Nextcloud id.
	 * @param string $domainObjectType  The `<app>:<schema>` literal of the host object.
	 * @param string $domainObjectRef   The host object's uuid.
	 *
	 * @return array{status: string, entry?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	public function start(string $uid, string $domainObjectType, string $domainObjectRef): array;

	/**
	 * Stop the caller's running timer.
	 *
	 * Writes the end onto the row that is already there. The entry has been one
	 * row throughout: it does not move, and nothing has to be reconciled between
	 * a timer and a booking, because there was never more than the booking.
	 *
	 * @param string $uid The calling user's Nextcloud id.
	 *
	 * @return array{status: string, entry?: array<string, mixed>}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	public function stop(string $uid): array;
}//end interface
