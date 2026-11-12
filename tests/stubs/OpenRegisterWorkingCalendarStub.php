<?php

/**
 * OpenRegister working calendar stubs — test-only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `WorkingCalendarReader` asks the container for openregister's
 * `Flow\Timer\WorkingCalendarService` by class, resolves the default calendar
 * and reads its non-working dates per year. In the standalone PHPUnit suite
 * openregister is absent, so these declare the public shape the reader calls,
 * matching openregister main:
 *
 * - `WorkingCalendarService::resolve(?string $calendarSlug, ?string $organisation): WorkingCalendar`
 * - `WorkingCalendarInterface::nonWorkingDates(int $year): array` (`Y-m-d` => name)
 * - `FlowTimerValidationException`, thrown when no calendar resolves
 *
 * `WorkingCalendar::fromArray()` is openregister's validating named
 * constructor; this stand-in reads only a test-only `nonWorkingDates` key
 * (year => [`Y-m-d` => name]) so a test can build a calendar with known dates.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real openregister classes always win on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception {

	/**
	 * Stand-in for openregister's timer validation exception.
	 */
	class FlowTimerValidationException extends \InvalidArgumentException {

	}//end class
}

namespace OCA\OpenRegister\Service\Flow\Timer {

	/**
	 * Openregister's working calendar contract.
	 */
	interface WorkingCalendarInterface {

		/**
		 * The calendar name.
		 *
		 * @return string
		 */
		public function getSlug(): string;

		/**
		 * The calendar time zone.
		 *
		 * @return string
		 */
		public function getTimezone(): string;

		/**
		 * The ISO weekdays that are working days.
		 *
		 * @return array
		 */
		public function getWorkingWeekdays(): array;

		/**
		 * Minute of the day work starts.
		 *
		 * @return int
		 */
		public function getDayStartsAtMinute(): int;

		/**
		 * Minute of the day work ends.
		 *
		 * @return int
		 */
		public function getDayEndsAtMinute(): int;

		/**
		 * Hours in one working day.
		 *
		 * @return float
		 */
		public function getHoursPerWorkingDay(): float;

		/**
		 * The non-working dates of a year.
		 *
		 * @param int $year The year.
		 *
		 * @return array<string, string> `Y-m-d` => name.
		 */
		public function nonWorkingDates(int $year): array;
	}//end interface

	/**
	 * Stand-in for openregister's working calendar value object.
	 */
	final class WorkingCalendar implements WorkingCalendarInterface {

		/**
		 * Constructor.
		 *
		 * @param array<int, array<string, string>> $byYear Year => [`Y-m-d` => name].
		 */
		private function __construct(private readonly array $byYear) {

		}//end __construct()

		/**
		 * Build a calendar from a definition.
		 *
		 * @param array<string, mixed> $definition The definition; the test-only `nonWorkingDates` key is read.
		 *
		 * @return self The calendar.
		 */
		public static function fromArray(array $definition): self {
			return new self(byYear: (array)($definition['nonWorkingDates'] ?? []));
		}//end fromArray()

		/**
		 * The calendar name.
		 *
		 * @return string
		 */
		public function getSlug(): string {
			return 'default';
		}//end getSlug()

		/**
		 * The calendar time zone.
		 *
		 * @return string
		 */
		public function getTimezone(): string {
			return 'Europe/Amsterdam';
		}//end getTimezone()

		/**
		 * The ISO weekdays that are working days.
		 *
		 * @return array
		 */
		public function getWorkingWeekdays(): array {
			return [1, 2, 3, 4, 5];
		}//end getWorkingWeekdays()

		/**
		 * Minute of the day work starts.
		 *
		 * @return int
		 */
		public function getDayStartsAtMinute(): int {
			return 540;
		}//end getDayStartsAtMinute()

		/**
		 * Minute of the day work ends.
		 *
		 * @return int
		 */
		public function getDayEndsAtMinute(): int {
			return 1020;
		}//end getDayEndsAtMinute()

		/**
		 * Hours in one working day.
		 *
		 * @return float
		 */
		public function getHoursPerWorkingDay(): float {
			return 8.0;
		}//end getHoursPerWorkingDay()

		/**
		 * The non-working dates of a year.
		 *
		 * @param int $year The year.
		 *
		 * @return array<string, string> `Y-m-d` => name.
		 */
		public function nonWorkingDates(int $year): array {
			return (array)($this->byYear[$year] ?? []);
		}//end nonWorkingDates()
	}//end class

	/**
	 * Stand-in for openregister's working calendar resolver.
	 */
	class WorkingCalendarService {

		/**
		 * Resolve the calendar for a timer.
		 *
		 * @param string|null $calendarSlug The calendar named on the timer, when any.
		 * @param string|null $organisation The subject's organisation, when any.
		 *
		 * @return WorkingCalendar The resolved calendar.
		 */
		public function resolve(?string $calendarSlug, ?string $organisation): WorkingCalendar {
			throw new \OCA\OpenRegister\Exception\FlowTimerValidationException('stub: no calendar seeded');
		}//end resolve()
	}//end class
}
