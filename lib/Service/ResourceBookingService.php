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
		$incoming = $this->incoming(booking: $booking);
		if ($incoming === null) {
			// The schema requires all three; a payload missing one is refused
			// before this service sees it.
			return null;
		}

		if ($incoming['end'] <= $incoming['start']) {
			return 'Een reservering moet eindigen na het begin.';
		}

		$unavailable = $this->unavailable(resource: $resource, resourceId: $incoming['resourceId']);
		if ($unavailable !== null || $resource === null) {
			return $unavailable;
		}

		$quantity = $this->capacity(resource: $resource);
		$overlapping = $this->overlapping(existing: $existing, incoming: $incoming);

		if ((count($overlapping) + 1) <= $quantity) {
			return null;
		}

		return $this->clashText(resource: $resource, clash: ['resourceId' => $incoming['resourceId'], 'overlapping' => $overlapping, 'quantity' => $quantity]);
	}//end refusal()

	/**
	 * The incoming booking's resource, own id, start and end, or null when the
	 * resource, the start or the end is missing.
	 *
	 * @param array<string, mixed> $booking The incoming ResourceBooking payload.
	 *
	 * @return array{resourceId: string, id: string, start: int, end: int}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function incoming(array $booking): ?array {
		$resourceId = trim((string)($booking['resourceId'] ?? ''));
		$start = $this->timestamp(value: ($booking['start'] ?? null));
		$end = $this->timestamp(value: ($booking['end'] ?? null));
		if ($resourceId === '' || $start === null || $end === null) {
			return null;
		}

		return ['resourceId' => $resourceId, 'id' => trim((string)($booking['id'] ?? '')), 'start' => $start, 'end' => $end];
	}//end incoming()

	/**
	 * Why a resource cannot be booked at all: it does not exist, or it is out
	 * of use. Null when it can be booked.
	 *
	 * @param array<string, mixed>|null $resource The Resource, or null when it does not resolve.
	 * @param string $resourceId The resource the booking asks for.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function unavailable(?array $resource, string $resourceId): ?string {
		if ($resource === null) {
			return 'De gereserveerde resource bestaat niet.';
		}

		if (($resource['active'] ?? true) === false) {
			return 'Resource ' . trim((string)($resource['name'] ?? $resourceId))
				. ' is buiten gebruik en kan niet worden gereserveerd.';
		}

		return null;
	}//end unavailable()

	/**
	 * The refusal that names how often the resource is already booked and the
	 * booking that stands in the way.
	 *
	 * @param array<string, mixed> $resource The Resource record.
	 * @param array{resourceId: string, overlapping: array<int, array<string, mixed>>, quantity: int} $clash The resource asked for, the clashing bookings and the capacity.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function clashText(array $resource, array $clash): string {
		return 'Resource ' . trim((string)($resource['name'] ?? $clash['resourceId']))
			. ' is in deze periode al ' . count($clash['overlapping']) . ' keer gereserveerd en er '
			. ($clash['quantity'] === 1 ? 'is er één' : 'zijn er ' . $clash['quantity'])
			. '. De reservering van ' . ($this->describe($clash['overlapping'][0])) . ' staat in de weg.';
	}//end clashText()

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
	 * @param array{resourceId: string, id: string, start: int, end: int} $incoming The incoming booking: its resource, its own id (empty when it is new), its start and its end.
	 *
	 * @return array<int, array<string, mixed>> The clashing bookings.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function overlapping(array $existing, array $incoming): array {
		$overlapping = [];
		foreach ($existing as $other) {
			if ($this->concerns(other: $other, incoming: $incoming) === false) {
				continue;
			}

			$otherStart = $this->timestamp(value: ($other['start'] ?? null));
			$otherEnd = $this->timestamp(value: ($other['end'] ?? null));
			if ($otherStart === null || $otherEnd === null) {
				continue;
			}

			if ($otherStart < $incoming['end'] && $incoming['start'] < $otherEnd) {
				$overlapping[] = $other;
			}
		}

		return $overlapping;
	}//end overlapping()

	/**
	 * Whether a stored booking could clash with the incoming one at all.
	 *
	 * @param array<string, mixed> $other The stored booking.
	 * @param array{resourceId: string, id: string, start: int, end: int} $incoming The incoming booking.
	 *
	 * @return bool True when it books the same resource and is not the booking under edit.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function concerns(array $other, array $incoming): bool {
		if (trim((string)($other['resourceId'] ?? '')) !== $incoming['resourceId']) {
			return false;
		}

		$otherId = trim((string)($other['id'] ?? ''));

		return ($otherId === '' || $otherId !== $incoming['id']);
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
