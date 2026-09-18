<?php

/**
 * Calendar Subscription Poller
 *
 * Reads each employee's subscribed iCalendar feed and caches the busy periods
 * it declares.
 *
 * READ ONLY, AND THAT IS A DECISION
 * ---------------------------------
 * GLPI imports an external calendar into its own planning; OTOBO does the same
 * through `AdminAppointmentImport`. Neither writes back, and neither should. An
 * employee's private agenda is not humaniq's to edit, and a two way sync makes
 * a deletion ambiguous in a way nobody can debug: did the person cancel it, or
 * did a sync lose it? So the only request this class ever issues against a
 * subscribed URL is a GET, and no humaniq object is ever created from an event
 * in the feed (design D7, REQ-AGD-005).
 *
 * A FAILED POLL KEEPS THE OLD CACHE
 * ---------------------------------
 * Emptying the cache on a failed poll would make a person look completely free
 * the moment their calendar server hiccups, and a planner would book over their
 * day. So a failure records the degradation on the subscription
 * (`lastPollStatus`) and leaves `busyPeriods` alone, the way
 * `leave-calendar-nc` records `skipped-no-calendar` rather than pretending.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Polls subscribed calendars and caches their busy periods.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
 */
class CalendarSubscriptionPoller {

	/**
	 * How long one feed may take before the poll gives up on it, in seconds.
	 *
	 * @var int
	 */
	public const TIMEOUT_SECONDS = 15;

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param IClientService $clientService Nextcloud's HTTP client factory.
	 * @param IcalBusyParser $parser Reads periods, and only periods, out of a feed.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IClientService $clientService,
		private readonly IcalBusyParser $parser,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Poll every active subscription.
	 *
	 * @return array{polled: int, ok: int, degraded: int} What the run did.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function pollAll(): array {
		$report = ['polled' => 0, 'ok' => 0, 'degraded' => 0];

		foreach ($this->gateway->loadAll('CalendarSubscription') as $subscription) {
			if (($subscription['active'] ?? true) === false) {
				continue;
			}

			++$report['polled'];
			if ($this->poll($subscription) === true) {
				++$report['ok'];
				continue;
			}

			++$report['degraded'];
		}

		return $report;
	}//end pollAll()

	/**
	 * Poll one subscription, writing the cache on success and only the
	 * degradation on failure.
	 *
	 * @param array<string, mixed> $subscription The CalendarSubscription row.
	 *
	 * @return bool True when the feed was read.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function poll(array $subscription): bool {
		$url = trim((string)($subscription['feedUrl'] ?? ''));
		$uuid = trim((string)($subscription['id'] ?? ''));
		if ($url === '' || $uuid === '') {
			return false;
		}

		try {
			// A GET, and never anything else. This is the only request humaniq
			// makes against a subscribed address.
			$response = $this->clientService->newClient()->get(
				$url,
				['timeout' => self::TIMEOUT_SECONDS]
			);
			$body = (string)$response->getBody();
		} catch (\Throwable $e) {
			$this->degrade(subscription: $subscription, uuid: $uuid, status: 'unreachable', detail: $e->getMessage());
			return false;
		}

		$periods = $this->parser->busyPeriods($body);
		if ($periods === [] && str_contains($body, 'BEGIN:VCALENDAR') === false) {
			// Something answered, and it was not a calendar. Treated as a failed
			// read rather than as an empty one, because an empty cache reads as
			// "this person is free all week".
			$this->degrade(
				subscription: $subscription,
				uuid: $uuid,
				status: 'unreadable',
				detail: 'the feed did not answer an iCalendar document'
			);
			return false;
		}

		$payload = $subscription;
		unset($payload['@self'], $payload['id']);
		$payload['busyPeriods'] = $periods;
		$payload['lastPolledAt'] = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
		$payload['lastPollStatus'] = 'ok';

		try {
			$this->gateway->save($payload, 'CalendarSubscription', $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: the busy periods of calendar subscription ' . $uuid . ' could not be cached: ' . $e->getMessage()
			);
			return false;
		}

		return true;
	}//end poll()

	/**
	 * Record a failed poll and leave the cached periods where they are.
	 *
	 * @param array<string, mixed> $subscription The CalendarSubscription row.
	 * @param string $uuid Its uuid.
	 * @param string $status `unreachable` or `unreadable`.
	 * @param string $detail What happened, for the log line.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	private function degrade(array $subscription, string $uuid, string $status, string $detail): void {
		$this->logger->info(
			'humaniq: kalenderabonnement ' . $uuid . ' kon niet worden gelezen (' . $status . '): ' . $detail
			. '. De eerder opgehaalde bezette tijd blijft staan.'
		);

		$payload = $subscription;
		unset($payload['@self'], $payload['id']);
		// busyPeriods is carried over UNCHANGED on purpose: a feed that is
		// briefly down must not empty somebody's agenda.
		$payload['lastPolledAt'] = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
		$payload['lastPollStatus'] = $status;

		try {
			$this->gateway->save($payload, 'CalendarSubscription', $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: the degradation of calendar subscription ' . $uuid . ' could not be recorded: ' . $e->getMessage()
			);
		}
	}//end degrade()
}//end class
