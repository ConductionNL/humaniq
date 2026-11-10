<?php

/**
 * Working Calendar Reader
 *
 * Reads the working calendar openregister publishes, and says so when it
 * cannot.
 *
 * WHY HUMANIQ ONLY READS IT
 * -------------------------
 * Decision D19 splits one word in two. Which days are working days for the
 * organisation, and which are feestdagen, is openregister's: it is true of the
 * gemeente, it is what a statutory term is counted against, and it has to mean
 * the same thing in every app. Which days and hours an individual works is
 * humaniq's, and that is {@see WorkingHoursService}. So humaniq holds no
 * national holiday, no freeze period and no week-numbering setting, and this
 * class never writes.
 *
 * HOW IT REACHES OPENREGISTER
 * ---------------------------
 * Typed: the container is asked for openregister's
 * {@see WorkingCalendarService} by class, the default calendar is resolved and
 * asked for the non-working dates of every year the range touches. A service
 * the container cannot give (openregister not installed) and a calendar that
 * cannot be resolved (no default seeded) land in the same place: no dates,
 * `resolved` false. A changed call shape is a type error and is NOT caught, so
 * a move on openregister's side fails loudly instead of answering every date
 * as a working day.
 *
 * WHAT A MISS MEANS
 * -----------------
 * `null` dates, never an empty list. An empty list says "the calendar was read
 * and nothing is a feestdag", which would let a national holiday be answered
 * as a full working day. The caller marks such an answer pattern-only, and the
 * miss is logged at INFO because an instance without openregister's calendar
 * is an expected, healthy degradation rather than a fault.
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
 * @spec openspec/specs/working-hours-per-person/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves openregister's working calendar, or reports that it could not.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
 */
class WorkingCalendarReader {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy openregister lookups).
	 * @param LoggerInterface $logger The app logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The dates openregister marks non-working inside a closed range.
	 *
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 *
	 * @return array{dates: array<int, string>|null, resolved: bool, reason: string|null}
	 *                                                                                   `dates` is null whenever `resolved` is false, so a caller cannot mistake an unread calendar for an empty one.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
	 */
	public function nonWorkingDates(DateTimeImmutable $from, DateTimeImmutable $to): array {
		try {
			$service = $this->container->get(WorkingCalendarService::class);
		} catch (ContainerExceptionInterface $e) {
			return $this->degraded(
				reason: 'no-working-calendar-service',
				detail: 'openregister publishes no working calendar on this instance: ' . $e->getMessage()
			);
		}

		if ($service instanceof WorkingCalendarService === false) {
			return $this->degraded(
				reason: 'no-working-calendar-service',
				detail: 'the container answered something other than ' . WorkingCalendarService::class
			);
		}

		try {
			$calendar = $service->resolve(calendarSlug: null, organisation: null);
		} catch (FlowTimerValidationException $e) {
			return $this->degraded(reason: 'call-failed', detail: $e->getMessage());
		}

		return [
			'dates' => $this->datesInRange(calendar: $calendar, from: $from, to: $to),
			'resolved' => true,
			'reason' => null,
		];
	}//end nonWorkingDates()

	/**
	 * The calendar's non-working dates that fall inside the range.
	 *
	 * The calendar answers per year, keyed `Y-m-d`, so every year the range
	 * touches is asked and the keys outside the range are dropped.
	 *
	 * @param WorkingCalendarInterface $calendar The resolved calendar.
	 * @param DateTimeImmutable        $from     First day, inclusive.
	 * @param DateTimeImmutable        $to       Last day, inclusive.
	 *
	 * @return array<int, string> The dates, ascending.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
	 */
	private function datesInRange(WorkingCalendarInterface $calendar, DateTimeImmutable $from, DateTimeImmutable $to): array {
		$first = $from->format('Y-m-d');
		$last = $to->format('Y-m-d');
		$dates = [];
		for ($year = (int)$from->format('Y'); $year <= (int)$to->format('Y'); $year++) {
			foreach (array_keys($calendar->nonWorkingDates(year: $year)) as $date) {
				$date = (string)$date;
				if ($date >= $first && $date <= $last) {
					$dates[] = $date;
				}
			}
		}

		$dates = array_values(array_unique($dates));
		sort($dates);

		return $dates;
	}//end datesInRange()

	/**
	 * Record one degradation and answer with no dates.
	 *
	 * @param string $reason A short machine-readable reason.
	 * @param string $detail What was actually found, for the log line.
	 *
	 * @return array{dates: null, resolved: false, reason: string}
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
	 */
	private function degraded(string $reason, string $detail): array {
		// An expected degradation path, so INFO: an instance without
		// openregister's working calendar is not broken, it simply answers
		// pattern-only. The reason is carried back so the caller can say which
		// degradation it is rather than only that there was one.
		$this->logger->info(
			'WorkingCalendarReader: werkkalender niet gelezen (' . $reason . '): ' . $detail
			. '. Het antwoord komt alleen uit het werkpatroon.'
		);

		return [
			'dates' => null,
			'resolved' => false,
			'reason' => $reason,
		];
	}//end degraded()
}//end class
