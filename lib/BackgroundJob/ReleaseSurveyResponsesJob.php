<?php

/**
 * Release Survey Responses Job
 *
 * Every hour, releases the held survey answers that are due as
 * responses, in shuffled batches, so a response's creation time is the
 * batch's and not the moment its invitation was answered.
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
 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\BackgroundJob;

use OCA\Humaniq\Service\SurveyResponseRelease;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Scheduled release of held survey answers.
 *
 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
 */
class ReleaseSurveyResponsesJob extends TimedJob {

	/**
	 * Constructor: runs every hour.
	 *
	 * @param ITimeFactory          $time    The clock.
	 * @param SurveyResponseRelease $release The release.
	 *
	 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function __construct(ITimeFactory $time, private readonly SurveyResponseRelease $release) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Release what is due today.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $argument is mandated by the TimedJob::run() signature.
	 */
	protected function run($argument): void {
		$this->release->release(today: $this->time->getDateTime()->format('Y-m-d'));
	}//end run()

}//end class
