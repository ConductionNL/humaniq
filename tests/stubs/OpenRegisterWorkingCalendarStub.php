<?php

/**
 * OpenRegister working calendar stubs — test-only.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	 * The part of openregister's working calendar contract the reader uses.
	 */
	interface WorkingCalendarInterface {

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
