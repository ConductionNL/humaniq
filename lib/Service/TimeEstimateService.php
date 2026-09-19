<?php

/**
 * Time Estimate Service
 *
 * What an object was expected to take, what it has taken, and what is left.
 *
 * WHY REMAINING IS NEVER STORED
 * -----------------------------
 * A stored remainder is a number that is right at the moment it is written and
 * wrong from the next write onward. Deleting a two-hour entry has to move it,
 * correcting one has to move it, and every path that touches a time entry would
 * have to remember to. Derived on read, `estimated - booked` cannot be stale
 * (REQ-HL-EST-002).
 *
 * WHY AN OVERRUN IS NEGATIVE AND NOT ZERO
 * ---------------------------------------
 * Clipping at zero makes "exactly used up" and "forty hours over" the same
 * picture, and the second one is the one somebody has to act on. So six hours
 * booked against a four-hour estimate reads as minus two.
 *
 * WHY NO ESTIMATE IS NOT AN ESTIMATE OF ZERO
 * ------------------------------------------
 * A remaining of zero means the estimate is used up. An object nobody estimated
 * has not used anything up, so it has no remainder at all, and the answer says
 * `hasEstimate` false rather than reporting a number the reader would believe
 * (REQ-HL-EST-003).
 *
 * Deliberately dependency-free: the caller supplies the estimates and the
 * entries.
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
 * @spec openspec/specs/hours-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Derives estimated, spent and remaining for one host object, and decides
 * whether one more booking may be written.
 *
 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
 */
class TimeEstimateService {

	/**
	 * The key a figure with no role is filed under.
	 *
	 * An empty string rather than null, because it is used as an array key and
	 * a null key silently becomes an empty string anyway. Naming it makes the
	 * "no role" bucket visible in every dump.
	 *
	 * @var string
	 */
	public const NO_ROLE = '';

	/**
	 * The `TimeEntry.origin` marking an entry a stopped timer wrote.
	 *
	 * Equal to `RunningTimerService::ORIGIN_TIMER`.
	 *
	 * @var string
	 */
	public const ORIGIN_TIMER = 'timer';

	/**
	 * Estimated, spent and remaining for one host object, in total and per
	 * role.
	 *
	 * @param array<array<string, mixed>> $estimates TimeEstimate rows, any object.
	 * @param array<array<string, mixed>> $entries TimeEntry rows, any object.
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The object's uuid.
	 *
	 * @return array{hasEstimate: bool, estimatedHours: float|null, spentHours: float, remainingHours: float|null, roles: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
	 */
	public function summary(
		array $estimates,
		array $entries,
		string $domainObjectType,
		string $domainObjectRef,
	): array {
		$mine = $this->forObject(rows: $estimates, type: $domainObjectType, ref: $domainObjectRef);
		$booked = $this->forObject(rows: $entries, type: $domainObjectType, ref: $domainObjectRef);

		$spentByRole = [];
		$spent = 0.0;
		foreach ($booked as $entry) {
			$hours = $this->hours($entry);
			$spent += $hours;
			$role = $this->role($entry);
			$spentByRole[$role] = (($spentByRole[$role] ?? 0.0) + $hours);
		}

		$roles = [];
		$estimatedTotal = 0.0;
		$hasEstimate = false;
		foreach ($mine as $estimate) {
			$hasEstimate = true;
			$role = $this->role($estimate);
			$estimated = $this->estimatedHours($estimate);
			$estimatedTotal += $estimated;
			$roleSpent = (float)($spentByRole[$role] ?? 0.0);

			$roles[] = [
				'role' => ($role === self::NO_ROLE ? null : $role),
				'estimatedHours' => round(num: $estimated, precision: 2),
				'spentHours' => round(num: $roleSpent, precision: 2),
				// Negative on purpose: an overrun clipped to zero is invisible.
				'remainingHours' => round(num: ($estimated - $roleSpent), precision: 2),
				'enforced' => (($estimate['enforced'] ?? false) === true),
			];
		}

		if ($hasEstimate === false) {
			// No estimate is not an estimate of zero. The spent total still
			// answers; the remainder does not exist.
			return [
				'hasEstimate' => false,
				'estimatedHours' => null,
				'spentHours' => round(num: $spent, precision: 2),
				'remainingHours' => null,
				'roles' => [],
			];
		}

		return [
			'hasEstimate' => true,
			'estimatedHours' => round(num: $estimatedTotal, precision: 2),
			'spentHours' => round(num: $spent, precision: 2),
			'remainingHours' => round(num: ($estimatedTotal - $spent), precision: 2),
			'roles' => $roles,
		];
	}//end summary()

	/**
	 * The refusal for writing one estimate, or null when it may be written.
	 *
	 * @param array<string, mixed> $estimate The incoming TimeEstimate.
	 * @param array<array<string, mixed>> $existing Every stored TimeEstimate.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-001
	 */
	public function refusalForEstimate(array $estimate, array $existing): ?string {
		$type = trim((string)($estimate['domainObjectType'] ?? ''));
		$ref = trim((string)($estimate['domainObjectRef'] ?? ''));
		if ($type === '' || $ref === '') {
			// The schema requires both; an estimate missing one is refused
			// before this service sees it.
			return null;
		}

		$role = $this->role($estimate);
		$id = trim((string)($estimate['id'] ?? ''));

		foreach ($this->forObject(rows: $existing, type: $type, ref: $ref) as $other) {
			$otherId = trim((string)($other['id'] ?? ''));
			if ($otherId !== '' && $otherId === $id) {
				// The estimate being edited is not its own duplicate.
				continue;
			}

			if ($this->role($other) !== $role) {
				continue;
			}

			return 'Voor dit object bestaat al een schatting'
				. ($role === self::NO_ROLE ? '' : ' voor de rol ' . $role)
				. '. Pas de bestaande schatting aan in plaats van een tweede toe te voegen.';
		}

		return null;
	}//end refusalForEstimate()

	/**
	 * The refusal for writing one time entry against an enforced estimate, or
	 * null when it may be written.
	 *
	 * @param array<string, mixed> $entry The incoming TimeEntry.
	 * @param array<array<string, mixed>> $estimates Every stored TimeEstimate.
	 * @param array<array<string, mixed>> $entries Every stored TimeEntry.
	 *
	 * @return string|null The refusal, naming the ceiling and what is left, or null.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	public function refusalForEntry(array $entry, array $estimates, array $entries): ?string {
		$type = trim((string)($entry['domainObjectType'] ?? ''));
		$ref = trim((string)($entry['domainObjectRef'] ?? ''));
		if ($type === '' || $ref === '') {
			// An entry booked against nothing meets no ceiling.
			return null;
		}

		if (trim((string)($entry['origin'] ?? '')) === self::ORIGIN_TIMER) {
			// Time already worked is a fact. A register that refuses a fact
			// reports a smaller number than the truth, so a stopped timer always
			// lands and the overrun shows up as a negative remainder.
			return null;
		}

		$role = $this->role($entry);
		$estimate = $this->estimateFor(estimates: $estimates, type: $type, ref: $ref, role: $role);
		if ($estimate === null || ($estimate['enforced'] ?? false) !== true) {
			// An estimate that is not enforced refuses nothing, and an object
			// with no estimate for this role has no ceiling at all.
			return null;
		}

		$ceiling = $this->estimatedHours($estimate);
		$entryId = trim((string)($entry['id'] ?? ''));

		$booked = $this->bookedAgainst(
			entries: $entries,
			type: $type,
			ref: $ref,
			role: $role,
			entryId: $entryId
		);

		$incoming = $this->hours($entry);
		if (($booked + $incoming) <= $ceiling) {
			return null;
		}

		$left = round(num: max(0.0, ($ceiling - $booked)), precision: 2);

		return 'De schatting voor dit object'
			. ($role === self::NO_ROLE ? '' : ' voor de rol ' . $role)
			. ' is ' . $this->number($ceiling) . ' uur en staat als plafond aan. Er is nog '
			. $this->number($left) . ' uur over, en deze boeking is ' . $this->number($incoming) . ' uur.';
	}//end refusalForEntry()

	/**
	 * The hours already booked against one object and role.
	 *
	 * An entry being corrected does not count against itself, so an edit is
	 * measured against the other entries and not against its own old value.
	 *
	 * @param array<array<string, mixed>> $entries Every stored TimeEntry.
	 * @param string $type The `<app>:<schema>` literal.
	 * @param string $ref The object's uuid.
	 * @param string $role The role, or {@see NO_ROLE}.
	 * @param string $entryId The incoming entry's own id, empty when it is new.
	 *
	 * @return float The booked hours.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	private function bookedAgainst(
		array $entries,
		string $type,
		string $ref,
		string $role,
		string $entryId
	): float {
		$booked = 0.0;
		foreach ($this->forObject(rows: $entries, type: $type, ref: $ref) as $other) {
			$otherId = trim((string)($other['id'] ?? ''));
			if ($otherId !== '' && $otherId === $entryId) {
				continue;
			}

			if ($this->role($other) !== $role) {
				continue;
			}

			$booked += $this->hours($other);
		}

		return $booked;
	}//end bookedAgainst()

	/**
	 * The estimate governing one object and role, preferring the role's own
	 * estimate over the object-wide one.
	 *
	 * @param array<array<string, mixed>> $estimates Every stored TimeEstimate.
	 * @param string $type The `<app>:<schema>` literal.
	 * @param string $ref The object's uuid.
	 * @param string $role The role, or {@see NO_ROLE}.
	 *
	 * @return array<string, mixed>|null The estimate, or null.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	public function estimateFor(array $estimates, string $type, string $ref, string $role): ?array {
		$mine = $this->forObject(rows: $estimates, type: $type, ref: $ref);

		foreach ($mine as $estimate) {
			if ($this->role($estimate) === $role) {
				return $estimate;
			}
		}

		if ($role === self::NO_ROLE) {
			return null;
		}

		// A ceiling on one role does not block another, so an entry carrying a
		// role only ever meets that role's estimate. The object-wide estimate is
		// deliberately NOT applied to it: otherwise an enforced total would
		// refuse the second role's first booking without naming it.
		return null;
	}//end estimateFor()

	/**
	 * The rows belonging to one host object.
	 *
	 * @param array<array<string, mixed>> $rows The rows.
	 * @param string $type The `<app>:<schema>` literal.
	 * @param string $ref The object's uuid.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
	 */
	private function forObject(array $rows, string $type, string $ref): array {
		$mine = [];
		foreach ($rows as $row) {
			if (trim((string)($row['domainObjectType'] ?? '')) !== $type) {
				continue;
			}

			if (trim((string)($row['domainObjectRef'] ?? '')) !== $ref) {
				continue;
			}

			$mine[] = $row;
		}

		return $mine;
	}//end forObject()

	/**
	 * One row's role, normalised.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The role, or {@see NO_ROLE}.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-001
	 */
	private function role(array $row): string {
		return trim((string)($row['role'] ?? ''));
	}//end role()

	/**
	 * One entry's booked hours.
	 *
	 * @param array<string, mixed> $entry The TimeEntry.
	 *
	 * @return float The hours.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-002
	 */
	private function hours(array $entry): float {
		$hours = ($entry['hours'] ?? null);
		if (is_numeric($hours) === false) {
			return 0.0;
		}

		return max(0.0, (float)$hours);
	}//end hours()

	/**
	 * One estimate's expected hours.
	 *
	 * @param array<string, mixed> $estimate The TimeEstimate.
	 *
	 * @return float The hours.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-001
	 */
	private function estimatedHours(array $estimate): float {
		$hours = ($estimate['estimatedHours'] ?? null);
		if (is_numeric($hours) === false) {
			return 0.0;
		}

		return max(0.0, (float)$hours);
	}//end estimatedHours()

	/**
	 * A number as a reader reads it, without a trailing `.00`.
	 *
	 * @param float $value The value.
	 *
	 * @return string The rendered number.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	private function number(float $value): string {
		if (abs(($value - round($value))) < 0.005) {
			return (string)(int)round($value);
		}

		return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
	}//end number()
}//end class
