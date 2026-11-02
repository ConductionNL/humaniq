<?php

/**
 * Agenda Booking Entries
 *
 * Resource bookings and cached external busy time, turned into agenda entries.
 *
 * Split out of {@see AgendaComposer} so neither class carries the whole of the
 * six sources. The AVG boundary the composer draws holds here too: a busy
 * entry says busy and carries no title, no location and no attendees, because
 * {@see CalendarSubscriptionPoller} never reads them out of the feed.
 *
 * Deliberately dependency-free: the caller supplies the already-fetched rows.
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
 * Builds the booking and busy-time entries of one subject's agenda.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-003
 */
class AgendaBookingEntries {

	/**
	 * Resource bookings as agenda entries, for a person or for the resource
	 * itself.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param AgendaWindow $window The subject, its employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-003
	 */
	public function bookingEntries(array $sources, AgendaWindow $window): array {
		$subjectType = $window->subject->type;
		$entries = [];
		foreach (($sources['bookings'] ?? []) as $booking) {
			$start = $this->stringOrNull(($booking['start'] ?? null));
			$end = $this->stringOrNull(($booking['end'] ?? null));
			if ($start === null || $end === null) {
				continue;
			}

			if (substr($start, 0, 10) > $window->to || substr($end, 0, 10) < $window->from) {
				continue;
			}

			$employeeId = trim((string)($booking['employeeId'] ?? ''));
			$resourceId = trim((string)($booking['resourceId'] ?? ''));

			if ($this->holdsSubject(holder: ['employeeId' => $employeeId, 'resourceId' => $resourceId], window: $window) === false) {
				continue;
			}

			$entries[] = [
				'kind' => 'booking',
				'subjectType' => ($subjectType === 'resource' ? 'resource' : 'employee'),
				'subjectId' => ($subjectType === 'resource' ? $resourceId : $employeeId),
				'start' => $start,
				'end' => $end,
				'label' => trim((string)($booking['purpose'] ?? 'Reservering')),
				'sourceType' => 'ResourceBooking',
				'sourceId' => trim((string)($booking['id'] ?? '')),
				'domainObjectType' => $this->stringOrNull(($booking['domainObjectType'] ?? null)),
				'domainObjectRef' => $this->stringOrNull(($booking['domainObjectRef'] ?? null)),
			];
		}

		return $entries;
	}//end bookingEntries()

	/**
	 * Cached external busy time as agenda entries.
	 *
	 * Title, location and attendees are not copied, because they are not read
	 * out of the feed in the first place: {@see CalendarSubscriptionPoller}
	 * caches periods only.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param AgendaWindow $window The subject's employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function busyEntries(array $sources, AgendaWindow $window): array {
		$entries = [];
		foreach (($sources['subscriptions'] ?? []) as $subscription) {
			$employeeId = trim((string)($subscription['employeeId'] ?? ''));
			if (in_array($employeeId, $window->employees, true) === false) {
				continue;
			}

			$periods = ($subscription['busyPeriods'] ?? []);
			if (is_array($periods) === false) {
				continue;
			}

			$entries = array_merge(
				$entries,
				$this->busyPeriodEntries(
					subscription: ['periods' => $periods, 'employeeId' => $employeeId, 'id' => trim((string)($subscription['id'] ?? ''))],
					window: $window
				)
			);
		}

		return $entries;
	}//end busyEntries()

	/**
	 * Whether one booking belongs on this subject's agenda.
	 *
	 * A resource agenda holds the bookings OF that resource; any other agenda
	 * holds the bookings made BY one of its employees.
	 *
	 * @param array{employeeId: string, resourceId: string} $holder The booking's employee (empty when it has none) and resource.
	 * @param AgendaWindow $window The subject and its employees.
	 *
	 * @return bool True when the booking belongs on the agenda.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-003
	 */
	private function holdsSubject(array $holder, AgendaWindow $window): bool {
		if ($window->subject->type === 'resource') {
			return ($holder['resourceId'] === $window->subject->id);
		}

		return ($holder['employeeId'] !== '' && in_array($holder['employeeId'], $window->employees, true) === true);
	}//end holdsSubject()

	/**
	 * One subscription's cached periods as busy entries.
	 *
	 * @param array{periods: array<int|string, mixed>, employeeId: string, id: string} $subscription The cached busy periods, the employee they belong to and the subscription's id.
	 * @param AgendaWindow $window The first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	private function busyPeriodEntries(array $subscription, AgendaWindow $window): array {
		$entries = [];
		foreach ($subscription['periods'] as $period) {
			if (is_array($period) === false) {
				continue;
			}

			$start = $this->stringOrNull(($period['start'] ?? null));
			$end = $this->stringOrNull(($period['end'] ?? null));
			if ($start === null || $end === null) {
				continue;
			}

			if (substr($start, 0, 10) > $window->to || substr($end, 0, 10) < $window->from) {
				continue;
			}

			$entries[] = [
				'kind' => 'busy',
				'subjectType' => 'employee',
				'subjectId' => $subscription['employeeId'],
				'start' => $start,
				'end' => $end,
				'label' => 'Bezet',
				'sourceType' => 'CalendarSubscription',
				'sourceId' => $subscription['id'],
			];
		}

		return $entries;
	}//end busyPeriodEntries()

	/**
	 * Narrow a raw value to a non-empty string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null Null when absent, not a string, or blank.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-003
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()
}//end class
