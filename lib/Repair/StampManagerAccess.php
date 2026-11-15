<?php

/**
 * Humaniq Stamp Manager Access Repair Step
 *
 * Stamps `managerUserId` and `deputyUserIds` on the Employee,
 * SickLeaveCase, LeaveTransaction and CompAdjustment records stored before
 * those fields existed. Registered after InitializeRegister, which imports
 * the fields, as a post-migration and install step. It runs until one pass
 * finishes without a failed record, then stores a done marker and skips
 * every later run; RefreshManagerAccessJob keeps the stamps current after.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ManagerAccessStamps;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Stamps the manager access fields once, on upgrade, install or `occ maintenance:repair`.
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class StampManagerAccess implements IRepairStep {

	/**
	 * App config key set after a pass in which nothing failed.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'manager_access_stamp_done';

	/**
	 * Constructor.
	 *
	 * @param ManagerAccessStamps $stamps The stamp pass.
	 * @param SettingsService $settingsService Availability check.
	 * @param LoggerInterface $logger The logger.
	 * @param IAppConfig $appConfig Holds the done marker.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(
		private readonly ManagerAccessStamps $stamps,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function getName(): string {
		return 'Stamp the manager and deputies on humaniq records they may read';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false || $this->appConfig->getValueBool(Application::APP_ID, self::DONE_KEY, false) === true) {
			return;
		}

		try {
			$failed = $this->report(output: $output, report: $this->stamps->run(false, gmdate('Y-m-d')));
		} catch (\Throwable $e) {
			$this->logger->error('Humaniq: manager access stamp failed', ['exception' => $e->getMessage()]);
			$output->warning('The manager access fields were not stamped and the step runs again: ' . $e->getMessage());
			return;
		}

		if ($failed === 0) {
			$this->appConfig->setValueBool(Application::APP_ID, self::DONE_KEY, true);
		}
	}//end run()

	/**
	 * Print the counts per schema and return how many records failed.
	 *
	 * @param IOutput $output The repair output.
	 * @param array<string, array{inspected: int, changed: int, failed: int}> $report The pass's counts.
	 *
	 * @return int
	 */
	private function report(IOutput $output, array $report): int {
		foreach ($report as $schema => $counts) {
			$output->info(sprintf('%s: %d of %d stamped, %d failed', $schema, $counts['changed'], $counts['inspected'], $counts['failed']));
		}

		$failed = array_sum(array_column($report, 'failed'));
		if ($failed > 0) {
			$output->warning(sprintf('%d records could not be stamped; the step runs again on the next upgrade or repair.', $failed));
		}

		return $failed;
	}//end report()

}//end class
