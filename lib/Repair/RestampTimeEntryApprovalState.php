<?php

/**
 * Humaniq Restamp Time Entry Approval State Repair Step
 *
 * Re-stamps `approvalState` and `approvedAt` on every TimeEntry from its
 * parent Timesheet (time-entry-approval-state D3). The timesheet is the
 * authority; this step heals an entry stored before the fields existed and
 * an edge the post-save listener missed. Idempotent: only an entry whose
 * stamp differs is written, so it runs on every upgrade, install and
 * `occ maintenance:repair`.
 *
 * @category Repair
 * @package  OCA\Humaniq\Repair
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
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimeEntryApprovalStamper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Re-stamps every time entry's approval state from its timesheet.
 *
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */
class RestampTimeEntryApprovalState implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param TimeEntryApprovalStamper $stamper         The shared entry stamp.
	 * @param SettingsService          $settingsService Whether OpenRegister is there.
	 * @param LoggerInterface          $logger          The logger.
	 */
	public function __construct(
		private readonly TimeEntryApprovalStamper $stamper,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
	 */
	public function getName(): string {
		return 'Stamp the approval state of each timesheet on its time entries';
	}//end getName()

	/**
	 * Re-stamp the entries; fails soft without OpenRegister.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			return;
		}

		try {
			$report = $this->stamper->restampAll();
		} catch (\Throwable $e) {
			$this->logger->error('Humaniq: time entry approval state restamp failed', ['exception' => $e->getMessage()]);
			$output->warning('The approval state of the time entries was not stamped: ' . $e->getMessage());
			return;
		}

		$output->info(
			sprintf(
				'TimeEntry approval state: %d of %d stamped, %d failed',
				$report['changed'],
				$report['inspected'],
				$report['failed']
			)
		);
		if ($report['failed'] > 0) {
			$output->warning(sprintf('%d time entries could not be stamped; the step runs again on the next upgrade or repair.', $report['failed']));
		}
	}//end run()

}//end class
