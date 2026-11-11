<?php

/**
 * Humaniq Restamp Open Manager Records Repair Step
 *
 * Rewrites an outdated `managerUserId` stamp on open records after the
 * manager resolution changed. Registered after MigrateHoursProcess as a
 * post-migration and install step. It runs until one pass finishes without
 * a failed record, then stores a done marker and skips every later run.
 * `occ humaniq:manager:restamp` runs the same pass on demand, with a dry run.
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ManagerStampRepair;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Restamps open records on every upgrade and every `occ maintenance:repair`.
 *
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */
class RestampOpenManagerRecords implements IRepairStep {

	/**
	 * App config key set after a pass in which nothing failed.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'manager_restamp_done';

	/**
	 * Constructor.
	 *
	 * @param ManagerStampRepair $repair The restamp pass.
	 * @param SettingsService $settingsService Availability check.
	 * @param LoggerInterface $logger The logger.
	 * @param IAppConfig $appConfig Holds the done marker.
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function __construct(
		private readonly ManagerStampRepair $repair,
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
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function getName(): string {
		return 'Restamp the manager on open humaniq records';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function run(IOutput $output): void {
		if ($this->ready($output) === false) {
			return;
		}

		$report = $this->report($output);
		if ($report === null) {
			return;
		}

		$failed = $this->failedIn(output: $output, report: $report);
		if ($failed > 0) {
			$output->warning(sprintf('%d records could not be restamped; the step runs again on the next upgrade or repair.', $failed));
			return;
		}

		$this->appConfig->setValueBool(Application::APP_ID, self::DONE_KEY, true);
	}//end run()

	/**
	 * Whether the step still has to run and OpenRegister is there to run it on.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return bool
	 */
	private function ready(IOutput $output): bool {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::DONE_KEY, false) === true) {
			return false;
		}

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not installed or enabled. Skipping the manager restamp.');
			return false;
		}

		return true;
	}//end ready()

	/**
	 * The restamp report per schema; null when the pass failed, which is
	 * reported and logged.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return array<string, array{inspected: int, open: int, changed: int, failed: int}>|null
	 */
	private function report(IOutput $output): array|null {
		try {
			return $this->repair->run(false);
		} catch (\Throwable $e) {
			$output->warning('Could not restamp the manager on open records: ' . $e->getMessage());
			$this->logger->error('Humaniq: manager restamp failed', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end report()

	/**
	 * Print the counts per schema and return the records that failed.
	 *
	 * @param IOutput                                                                    $output The repair output.
	 * @param array<string, array{inspected: int, open: int, changed: int, failed: int}> $report The restamp report.
	 *
	 * @return int
	 */
	private function failedIn(IOutput $output, array $report): int {
		$failed = 0;
		foreach ($report as $schema => $counts) {
			$output->info(sprintf('%s: inspected=%d open=%d restamped=%d failed=%d', $schema, $counts['inspected'], $counts['open'], $counts['changed'], $counts['failed']));
			$failed += $counts['failed'];
		}

		return $failed;
	}//end failedIn()

}//end class
