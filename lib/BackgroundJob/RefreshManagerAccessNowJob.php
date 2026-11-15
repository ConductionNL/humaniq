<?php

/**
 * Refresh Manager Access Now Job
 *
 * Queued by an org or deputy change: restamps `managerUserId` and
 * `deputyUserIds` once on every record that carries them, so a new
 * placement, a new unit manager or a new deputy reaches the read rules
 * without waiting for the scheduled refresh. Nextcloud keeps one pending
 * copy however often it is queued.
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
use OCP\BackgroundJob\QueuedJob;

/**
 * One-off manager access refresh.
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class RefreshManagerAccessNowJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ManagerAccessStamps $stamps The restamp pass.
	 * @param ITimeFactory $time The clock.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(private readonly ManagerAccessStamps $stamps, ITimeFactory $time) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the restamp pass once for today.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $argument is mandated by the QueuedJob::run() signature.
	 */
	protected function run($argument): void {
		$this->stamps->refresh(today: $this->time->getDateTime()->format('Y-m-d'));
	}//end run()

}//end class
