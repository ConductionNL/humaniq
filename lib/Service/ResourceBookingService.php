<?php

/**
 * Resource Booking Service
 *
 * Whether one more booking of a resource fits beside the bookings it already
 * has.
 *
 * WHY A REFUSAL AND NOT A REPORT
 * ------------------------------
 * GLPI's Reservations let a reader see a clash after the fact. A room booked
 * twice for one hoorzitting is a person standing in a corridor, and nobody
 * reads the clash report before walking to the room. So the second write is
 * refused, in the write path, and the refusal names the booking that blocks it
 * so the caller can go and talk to whoever holds it (design D6).
 *
 * WHY QUANTITY AND NOT A BOOLEAN
 * ------------------------------
 * A room is one room. A geluidsmeter may be two. Three inspectors and two
 * meters is not a double booking, it is two bookings and a queue, and a rule
 * that cannot express that would have people entering fake resources called
 * "Geluidsmeter 2" to get around it.
 *
 * Deliberately dependency-free: the caller supplies the resource and the
 * existing bookings.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Decides whether a resource booking may be written.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
 */
class ResourceBookingService {

	/**
	 * The refusal for one booking, or null when it may be written.
	 *
	 * @param array<string, mixed> $booking The incoming ResourceBooking payload.
	 * @param array<string, mixed>|null $resource The Resource it books, or null when it does not resolve.
	 * @param array<array<string, mixed>> $existing Every booking already stored, any resource.
	 *
	 * @return string|null The refusal, naming what blocks it, or null.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	public function refusal(array $booking, ?array $resource, array $existing): ?string {
		$resourceId = trim((string)($booking['resourceId'] ?? ''));
		$start = $this->timestamp(value: ($booking['start'] ?? null));
		$end = $this->timestamp(value: ($booking['end'] ?? null));
		if ($resourceId === '' || $start === null || $end === null) {
			// The schema requires all three; a payload missing one is refused
			// before this service sees it.
			return null;
		}

		if ($end <= $start) {
			return 'Een reservering moet eindigen na het begin.';
		}

		if ($resource === null) {
			return 'De gereserveerde resource bestaat niet.';
		}

		if (($resource['active'] ?? true) === false) {
			return 'Resource ' . trim((string)($resource['name'] ?? $resourceId))
				. ' is buiten gebruik en kan niet worden gereserveerd.';
		}

		$quantity = $this->capacity(resource: $resource);
		$overlapping = $this->overlapping(
			existing: $existing,
			resourceId: $resourceId,
			bookingId: trim((string)($booking['id'] ?? '')),
			start: $start,
			end: $end
		);

		if ((count($overlapping) + 1) <= $quantity) {
			return null;
		}

		return 'Resource ' . trim((string)($resource['name'] ?? $resourceId))
			. ' is in deze periode al ' . count($overlapping) . ' keer gereserveerd en er '
			. ($quantity === 1 ? 'is er één' : 'zijn er ' . $quantity)
			. '. De reservering van ' . ($this->describe($overlapping[0])) . ' staat in de weg.';
	}//end refusal()

	/**
	 * How many bookings of one resource may run at the same time.
	 *
	 * @param array<string, mixed> $resource The Resource record.
	 *
	 * @return int The capacity, never below one.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function capacity(array $resource): int {
		$quantity = ($resource['quantity'] ?? 1);
		if (is_numeric($quantity) === false || (int) $quantity < 1) {
			return 1;
		}

		return (int) $quantity;
	}//end capacity()

	/**
	 * The stored bookings of one resource that run during a period.
	 *
	 * Touching periods do not overlap: 10:00-12:00 and 12:00-13:00 are two
	 * bookings of one room, not a clash. A booking being edited is not its own
	 * clash either, so its own id is skipped.
	 *
	 * @param array<array<string, mixed>> $existing Every booking already stored, any resource.
	 * @param string $resourceId The resource the incoming booking asks for.
	 * @param string $bookingId The incoming booking's own id, empty when it is new.
	 * @param int $start The incoming booking's start.
	 * @param int $end The incoming booking's end.
	 *
	 * @return array<int, array<string, mixed>> The clashing bookings.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function overlapping(
		array $existing,
		string $resourceId,
		string $bookingId,
		int $start,
		int $end
	): array {
		$overlapping = [];
		foreach ($existing as $other) {
			if ($this->concerns(other: $other, resourceId: $resourceId, bookingId: $bookingId) === false) {
				continue;
			}

			$otherStart = $this->timestamp(value: ($other['start'] ?? null));
			$otherEnd = $this->timestamp(value: ($other['end'] ?? null));
			if ($otherStart === null || $otherEnd === null) {
				continue;
			}

			if ($otherStart < $end && $start < $otherEnd) {
				$overlapping[] = $other;
			}
		}

		return $overlapping;
	}//end overlapping()

	/**
	 * Whether a stored booking could clash with the incoming one at all.
	 *
	 * @param array<string, mixed> $other The stored booking.
	 * @param string $resourceId The resource the incoming booking asks for.
	 * @param string $bookingId The incoming booking's own id, empty when it is new.
	 *
	 * @return bool True when it books the same resource and is not the booking under edit.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function concerns(array $other, string $resourceId, string $bookingId): bool {
		if (trim((string)($other['resourceId'] ?? '')) !== $resourceId) {
			return false;
		}

		$otherId = trim((string)($other['id'] ?? ''));

		return ($otherId === '' || $otherId !== $bookingId);
	}//end concerns()

	/**
	 * A short description of the booking that blocks another.
	 *
	 * @param array<string, mixed> $booking The blocking booking.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function describe(array $booking): string {
		$who = trim((string)($booking['employeeId'] ?? ''));
		$start = trim((string)($booking['start'] ?? ''));
		$end = trim((string)($booking['end'] ?? ''));

		$description = ($start . ' tot ' . $end);
		if ($who !== '') {
			$description .= ' (medewerker ' . $who . ')';
		}

		return $description;
	}//end describe()

	/**
	 * Narrow a raw value to a timestamp.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int|null The timestamp, or null when unusable.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function timestamp(mixed $value): ?int {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$timestamp = strtotime(trim($value));
		if ($timestamp === false) {
			return null;
		}

		return $timestamp;
	}//end timestamp()
}//end class
