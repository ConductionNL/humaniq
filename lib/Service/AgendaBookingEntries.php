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
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $subjectType The subject kind.
	 * @param string $subjectId The subject's id.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-003
	 */
	public function bookingEntries(
		array $sources,
		array $employees,
		string $subjectType,
		string $subjectId,
		string $from,
		string $to,
	): array {
		$entries = [];
		foreach (($sources['bookings'] ?? []) as $booking) {
			$start = $this->stringOrNull(($booking['start'] ?? null));
			$end = $this->stringOrNull(($booking['end'] ?? null));
			if ($start === null || $end === null) {
				continue;
			}

			if (substr($start, 0, 10) > $to || substr($end, 0, 10) < $from) {
				continue;
			}

			$employeeId = trim((string)($booking['employeeId'] ?? ''));
			$resourceId = trim((string)($booking['resourceId'] ?? ''));

			$holdsSubject = $this->holdsSubject(
				employeeId: $employeeId,
				resourceId: $resourceId,
				employees: $employees,
				subjectType: $subjectType,
				subjectId: $subjectId
			);

			if ($holdsSubject === false) {
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
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function busyEntries(array $sources, array $employees, string $from, string $to): array {
		$entries = [];
		foreach (($sources['subscriptions'] ?? []) as $subscription) {
			$employeeId = trim((string)($subscription['employeeId'] ?? ''));
			if (in_array($employeeId, $employees, true) === false) {
				continue;
			}

			$periods = ($subscription['busyPeriods'] ?? []);
			if (is_array($periods) === false) {
				continue;
			}

			$entries = array_merge(
				$entries,
				$this->busyPeriodEntries(
					periods: $periods,
					employeeId: $employeeId,
					subscriptionId: trim((string)($subscription['id'] ?? '')),
					from: $from,
					to: $to
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
	 * @param string $employeeId The booking's employee, empty when it has none.
	 * @param string $resourceId The booking's resource.
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $subjectType The subject kind.
	 * @param string $subjectId The subject's id.
	 *
	 * @return bool True when the booking belongs on the agenda.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-003
	 */
	private function holdsSubject(
		string $employeeId,
		string $resourceId,
		array $employees,
		string $subjectType,
		string $subjectId
	): bool {
		if ($subjectType === 'resource') {
			return ($resourceId === $subjectId);
		}

		return ($employeeId !== '' && in_array($employeeId, $employees, true) === true);
	}//end holdsSubject()

	/**
	 * One subscription's cached periods as busy entries.
	 *
	 * @param array<int|string, mixed> $periods The cached busy periods.
	 * @param string $employeeId The employee the subscription belongs to.
	 * @param string $subscriptionId The subscription's id.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	private function busyPeriodEntries(
		array $periods,
		string $employeeId,
		string $subscriptionId,
		string $from,
		string $to
	): array {
		$entries = [];
		foreach ($periods as $period) {
			if (is_array($period) === false) {
				continue;
			}

			$start = $this->stringOrNull(($period['start'] ?? null));
			$end = $this->stringOrNull(($period['end'] ?? null));
			if ($start === null || $end === null) {
				continue;
			}

			if (substr($start, 0, 10) > $to || substr($end, 0, 10) < $from) {
				continue;
			}

			$entries[] = [
				'kind' => 'busy',
				'subjectType' => 'employee',
				'subjectId' => $employeeId,
				'start' => $start,
				'end' => $end,
				'label' => 'Bezet',
				'sourceType' => 'CalendarSubscription',
				'sourceId' => $subscriptionId,
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
