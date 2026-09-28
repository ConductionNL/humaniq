<?php

/**
 * Retention Expiry Job
 *
 * compliance-retention-expiry: once a day, releases the humaniq statutory
 * floor holds whose date has passed and marks expired records for
 * OpenRegister's destruction list (`RetentionExpiryService`). Off until an
 * admin sets `retention_expiry_enabled`; while off it logs what it would do.
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
 * @spec openspec/specs/personnel-retention-expiry/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\BackgroundJob;

use DateTimeImmutable;
use OCA\Humaniq\Service\RetentionExpiryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily retention expiry walk.
 */
class RetentionExpiryJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * @param ITimeFactory           $time    Time factory.
	 * @param RetentionExpiryService $service The walk.
	 * @param LoggerInterface        $logger  Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RetentionExpiryService $service,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		$this->runExpiry();
	}//end run()

	/**
	 * Run the walk for today and log its summary.
	 *
	 * @return array{enabled: bool, released: int, marked: int, wouldRelease: int, wouldMark: int}
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function runExpiry(): array {
		$today = (new DateTimeImmutable())->setTimestamp($this->time->getTime());
		$summary = $this->service->run($today);
		$this->logger->info('RetentionExpiryJob: run complete', $summary);

		return $summary;
	}//end runExpiry()

}//end class
