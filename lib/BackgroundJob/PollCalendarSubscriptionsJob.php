<?php

/**
 * Poll Calendar Subscriptions Job
 *
 * Reads every active subscribed iCalendar feed on a schedule and refreshes the
 * cached busy time behind the agenda and the availability answer.
 *
 * WHY A JOB AND NOT A READ-TIME FETCH
 * -----------------------------------
 * The availability endpoint answers for a whole team. Fetching each person's
 * feed while a planner waits would make one slow calendar server the latency of
 * the whole answer, and a feed that hangs would hang the page. Polled and
 * cached, a slow feed costs a stale hour, which is what a busy-time cache is
 * for (REQ-AGD-005).
 *
 * A failed poll keeps the previous cache: see
 * {@see \OCA\Humaniq\Service\CalendarSubscriptionPoller}.
 *
 * @category BackgroundJob
 * @package  OCA\Humaniq\BackgroundJob
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

namespace OCA\Humaniq\BackgroundJob;

use OCA\Humaniq\Service\CalendarSubscriptionPoller;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Refreshes the cached busy time of every active calendar subscription.
 */
class PollCalendarSubscriptionsJob extends TimedJob {

	/**
	 * How often the job fires (about every fifteen minutes).
	 *
	 * Busy time is used to decide whether somebody can be booked this
	 * afternoon, so an hourly cache would be answering about this morning.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 900;

	/**
	 * @param ITimeFactory $time Time factory.
	 * @param CalendarSubscriptionPoller $poller The poller itself.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CalendarSubscriptionPoller $poller,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

	}//end __construct()

	/**
	 * The TimedJob entry point.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	protected function run($argument): void {
		try {
			$summary = $this->poller->pollAll();
		} catch (\Throwable $e) {
			// A poll run that throws must not take the cron round down with it.
			$this->logger->warning(
				'PollCalendarSubscriptionsJob: the run failed: ' . $e->getMessage()
			);
			return;
		}

		$this->logger->info(
			'PollCalendarSubscriptionsJob: run complete',
			[
				'polled' => $summary['polled'],
				'ok' => $summary['ok'],
				'degraded' => $summary['degraded'],
			]
		);
	}//end run()
}//end class
