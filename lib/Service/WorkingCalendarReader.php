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
 * Duck-typed and container-resolved behind a `class_exists()` guard, the way
 * `hours-leaf` resolves `ObjectService` and `leave-calendar-nc` resolves
 * `CalDavBackend` (ADR-083: establish availability before reaching). A class
 * that is absent, a method that is missing and a call that throws all land in
 * the same place: no dates, `resolved` false.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves openregister's working calendar, or reports that it could not.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
 */
class WorkingCalendarReader {

	/**
	 * The openregister service that publishes the working calendar, in the
	 * order it is looked for.
	 *
	 * More than one name because the calendar's owning service has moved once
	 * already, and a hard-coded single name turns a rename into a silent
	 * no-op: nothing errors, every date simply answers as a working day.
	 *
	 * @var array<int, string>
	 */
	public const CANDIDATE_SERVICES = [
		'OCA\OpenRegister\Service\WorkingCalendarService',
		'OCA\OpenRegister\Service\Calendar\WorkingCalendarService',
	];

	/**
	 * The method each candidate is asked for.
	 *
	 * @var string
	 */
	public const CANDIDATE_METHOD = 'nonWorkingDates';

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
		$resolved = $this->resolveService();
		$service = $resolved['service'];
		$name = $resolved['name'];

		if ($service === null) {
			return $this->degraded(
				reason: 'no-working-calendar-service',
				detail: 'openregister publishes no working calendar on this instance'
			);
		}

		if (method_exists($service, self::CANDIDATE_METHOD) === false) {
			return $this->degraded(
				reason: 'method-missing',
				detail: ((string)$name) . ' has no ' . self::CANDIDATE_METHOD . '()'
			);
		}

		try {
			$raw = $service->{self::CANDIDATE_METHOD}($from->format('Y-m-d'), $to->format('Y-m-d'));
		} catch (\Throwable $e) {
			return $this->degraded(reason: 'call-failed', detail: $e->getMessage());
		}

		if (is_array($raw) === false) {
			return $this->degraded(
				reason: 'unreadable-answer',
				detail: 'the working calendar answered something other than a list of dates'
			);
		}

		return [
			'dates' => $this->isoDates(raw: $raw),
			'resolved' => true,
			'reason' => null,
		];
	}//end nonWorkingDates()

	/**
	 * The openregister working-calendar service, if this instance has one.
	 *
	 * Both names are tried because the service moved namespace; the probe is
	 * by name on purpose, so an instance without openregister keeps working
	 * instead of failing to boot.
	 *
	 * @return array{service: object|null, name: string|null} The service and the name it answered to.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
	 */
	private function resolveService(): array {
		$name = null;
		foreach (self::CANDIDATE_SERVICES as $candidate) {
			if (class_exists($candidate) === false) {
				continue;
			}

			$name = $candidate;
			try {
				$service = $this->container->get($candidate);
			} catch (\Throwable $e) {
				continue;
			}

			if ($service !== null) {
				return [
					'service' => $service,
					'name' => $name,
				];
			}
		}

		return [
			'service' => null,
			'name' => $name,
		];
	}//end resolveService()

	/**
	 * The answer narrowed to unique ISO dates.
	 *
	 * @param array<int|string, mixed> $raw What the working calendar answered.
	 *
	 * @return array<int, string> The dates.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-004
	 */
	private function isoDates(array $raw): array {
		$dates = [];
		foreach ($raw as $value) {
			if (is_string($value) === false || trim($value) === '') {
				continue;
			}

			$dates[] = substr($value, 0, 10);
		}

		return array_values(array_unique($dates));
	}//end isoDates()

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
