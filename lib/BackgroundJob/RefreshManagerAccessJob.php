<?php

/**
 * Refresh Manager Access Job
 *
 * Every six hours, restamps `managerUserId` and `deputyUserIds` on every
 * record that carries them, so a deputy period that starts or ends and a
 * placement that begins or ends on a date reach the read rules without a
 * save of the record.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\BackgroundJob;

use OCA\Humaniq\Service\ManagerAccessStamps;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Scheduled manager access refresh.
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class RefreshManagerAccessJob extends TimedJob {

	/**
	 * Constructor: runs every six hours, outside busy hours when possible.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param ManagerAccessStamps $stamps The restamp pass.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(ITimeFactory $time, private readonly ManagerAccessStamps $stamps) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 21600);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Run the restamp pass for today.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $argument is mandated by the TimedJob::run() signature.
	 */
	protected function run($argument): void {
		$this->stamps->refresh(today: $this->time->getDateTime()->format('Y-m-d'));
	}//end run()

}//end class
