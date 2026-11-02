<?php

/**
 * The caller's running timer: starting one, stopping it, and finding it again.
 *
 * A running timer IS a time entry. `startedAt` is set, `endedAt` is absent, and
 * `origin` says `timer`. There is no second store, no session key and no cached
 * flag, so there is nothing that can disagree with the row: every surface that
 * can read humaniq's register can see that an object is being worked on right
 * now, on any device the person happens to be using.
 *
 * WHY THE SERVER DECIDES "ONE TIMER PER USER". A guard in the widget is not a
 * guard. Two tabs, two objects, a reload part-way through a request, or a second
 * device each get past it, and each one writes another open row that no stop
 * will ever close. The constraint spans rows the caller never sends, so it is
 * resolved here, from the caller's identity.
 *
 * WHY NOTHING TAKES AN ENTRY ID. Every method resolves the entry from the
 * calling user rather than from an id in the request, so there is no reference
 * for a caller to swap for someone else's (ADR-005 Rule 3). `stop()` takes no
 * arguments at all.
 *
 * @category  Service
 * @package   OCA\Humaniq\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Listener\HoursWriteRefusedException;
use Throwable;

/**
 * Starts, stops and resolves the calling user's single running timer.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
 */
class RunningTimerService {

	/**
	 * The schema slug every timer entry is written to.
	 *
	 * @var string
	 */
	public const TIMEENTRY_SLUG = 'timeentry';

	/**
	 * The `origin` value marking an entry as a running timer.
	 *
	 * Equal to {@see TimeEntryHoursDeriver::ORIGIN_TIMER} and to the enum member
	 * on `TimeEntry`. The deriver reads it to tell a running timer apart from a
	 * finished booking that lost its end, so a drift between the three shows up
	 * as a timer that cannot be written.
	 *
	 * @var string
	 */
	public const ORIGIN_TIMER = TimeEntryHoursDeriver::ORIGIN_TIMER;

	/**
	 * Outcome: a timer is now running, or was found running.
	 *
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Outcome: the caller already had a timer running, so nothing was started.
	 *
	 * @var string
	 */
	public const STATUS_ALREADY_RUNNING = 'already-running';

	/**
	 * Outcome: the timer was stopped and the entry now carries its hours.
	 *
	 * @var string
	 */
	public const STATUS_STOPPED = 'stopped';

	/**
	 * Outcome: no timer is running for this caller.
	 *
	 * @var string
	 */
	public const STATUS_NONE = 'none';

	/**
	 * Outcome: the request could not be acted on as sent.
	 *
	 * @var string
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway The hours process's door to OpenRegister.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
	) {
	}//end __construct()

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
	public function running(string $uid): ?array {
		$uid = trim($uid);
		if ($uid === '') {
			return null;
		}

		try {
			$rows = $this->gateway->findFiltered(
				self::TIMEENTRY_SLUG,
				[
					'userId' => $uid,
					'origin' => self::ORIGIN_TIMER,
				]
			);
		} catch (Throwable) {
			// A register that cannot be read is not a timer that is not
			// running. The caller renders its unknown state; it does not offer
			// a start that would write a second open row.
			throw new HoursWriteRefusedException('De lopende timer kon niet worden opgehaald.');
		}

		$open = [];
		foreach ($rows as $row) {
			if (trim((string)($row['endedAt'] ?? '')) === '') {
				$open[] = $row;
			}
		}

		if ($open === []) {
			return null;
		}

		// Newest first, so a pre-existing duplicate from before this guard
		// existed resolves to the one the person most plausibly started.
		usort(
			$open,
			static fn (array $a, array $b): int => strcmp(
				(string)($b['startedAt'] ?? ''),
				(string)($a['startedAt'] ?? '')
			)
		);

		return $open[0];
	}//end running()

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
	public function start(string $uid, string $domainObjectType, string $domainObjectRef): array {
		$uid = trim($uid);
		$domainObjectType = trim($domainObjectType);
		$domainObjectRef = trim($domainObjectRef);

		if ($uid === '') {
			return ['status' => self::STATUS_INVALID, 'error' => 'Log in om een timer te starten.'];
		}

		if ($domainObjectType === '' || $domainObjectRef === '') {
			// Both or neither, the same rule the schema states for the pair:
			// a bare uuid nobody can resolve is worse than no reference.
			return ['status' => self::STATUS_INVALID, 'error' => 'Een timer hoort bij een object. Geef het type en de verwijzing mee.'];
		}

		$existing = $this->running($uid);
		if ($existing !== null) {
			return ['status' => self::STATUS_ALREADY_RUNNING, 'entry' => $existing];
		}

		$saved = $this->gateway->save(
			[
				'startedAt' => gmdate('c'),
				'origin' => self::ORIGIN_TIMER,
				'domainObjectType' => $domainObjectType,
				'domainObjectRef' => $domainObjectRef,
			],
			self::TIMEENTRY_SLUG
		);

		return ['status' => self::STATUS_RUNNING, 'entry' => $this->toArray($saved)];
	}//end start()

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
	public function stop(string $uid): array {
		$running = $this->running(trim($uid));
		if ($running === null) {
			return ['status' => self::STATUS_NONE];
		}

		$uuid = $this->idOf($running);
		if ($uuid === '') {
			return ['status' => self::STATUS_NONE];
		}

		$payload = $running;
		unset($payload['id'], $payload['@self']);
		$payload['endedAt'] = gmdate('c');

		$saved = $this->gateway->save($payload, self::TIMEENTRY_SLUG, $uuid);

		return ['status' => self::STATUS_STOPPED, 'entry' => $this->toArray($saved)];
	}//end stop()

	/**
	 * The uuid of a register row, wherever it carries it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = ($row['@self'] ?? []);

		return trim(
			(string)($row['id'] ?? ((is_array($self) === true ? ($self['id'] ?? '') : '')))
		);
	}//end idOf()

	/**
	 * Normalise a saved ObjectEntity to an array.
	 *
	 * @param mixed $row The saved row.
	 *
	 * @return array<string, mixed> The row as an array.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

}//end class
