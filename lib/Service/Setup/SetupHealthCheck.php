<?php

/**
 * The setup wizard's health check, shown on the Klaar step.
 *
 * Names the three things an administrator needs to know before they start:
 * how many schemas the register import left behind, whether Nextcloud's
 * background jobs run, and which year of payroll tables is loaded. Any one
 * that is missing makes the whole check read as failed, and the line says
 * which one, so a failed import is never reported as a passed check.
 *
 * @category Service
 * @package  OCA\Humaniq\Service\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-klaar-step-shall-recap-every-answer-with-a-way-back-to-change-it-req-fts-011
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service\Setup;

use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reports the register import, the background jobs and the payroll tables as one pass/fail line.
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
 */
class SetupHealthCheck {

	/**
	 * OpenRegister's register mapper, reached by name so an instance without OpenRegister still answers.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * How long ago the last background job may have run for the jobs to count as running, in seconds.
	 *
	 * The same hour Nextcloud's own admin overview uses before it warns.
	 *
	 * @var integer
	 */
	public const CRON_MAX_AGE = 3600;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Serves OpenRegister's register mapper.
	 * @param SettingsService    $settings  Names humaniq's register and says whether OpenRegister is on.
	 * @param IAppConfig         $appConfig Holds Nextcloud's last background job run.
	 * @param ITimeFactory       $time      The current time.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Run the check.
	 *
	 * @return array{passed:bool,schemas:int,backgroundJobs:bool,payrollTablesYear:int|null,failures:array<int,string>,message:string}
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
	 */
	public function check(): array {
		$schemas = $this->schemaCount();
		$jobs    = $this->backgroundJobsRun();
		$year    = self::latestYear(ids: TaxTables::availableIds());

		return self::report(schemas: $schemas, backgroundJobs: $jobs, payrollTablesYear: $year);
	}//end check()

	/**
	 * Turn the three findings into the report the Klaar step shows.
	 *
	 * @param integer      $schemas           Schemas in humaniq's register; 0 when the import left none.
	 * @param boolean      $backgroundJobs    Whether the background jobs ran within the last hour.
	 * @param integer|null $payrollTablesYear The newest loaded payroll tables year, or null.
	 *
	 * @return array{passed:bool,schemas:int,backgroundJobs:bool,payrollTablesYear:int|null,failures:array<int,string>,message:string}
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
	 */
	public static function report(int $schemas, bool $backgroundJobs, ?int $payrollTablesYear): array {
		$failures = [];
		if ($schemas === 0) {
			$failures[] = 'the register was not imported';
		}

		if ($backgroundJobs === false) {
			$failures[] = 'background jobs are not running';
		}

		if ($payrollTablesYear === null) {
			$failures[] = 'no payroll tables are loaded';
		}

		$message = 'Check passed: register imported with ' . $schemas . ' schemas, background jobs running, payroll tables ' . $payrollTablesYear . ' loaded.';
		if ($failures !== []) {
			$message = 'Check failed: ' . implode(', ', $failures) . '.';
		}

		return [
			'passed'            => ($failures === []),
			'schemas'           => $schemas,
			'backgroundJobs'    => $backgroundJobs,
			'payrollTablesYear' => $payrollTablesYear,
			'failures'          => $failures,
			'message'           => $message,
		];
	}//end report()

	/**
	 * The newest year among payroll table ids such as `nl-2026`, or null when none names a year.
	 *
	 * @param array<int,string> $ids The table ids.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
	 */
	public static function latestYear(array $ids): ?int {
		$latest = null;
		foreach ($ids as $id) {
			if (preg_match('/(?:^|\D)((?:19|20)\d{2})(?:\D|$)/', (string)$id, $match) !== 1) {
				continue;
			}

			$year = (int)$match[1];
			if ($latest === null || $year > $latest) {
				$latest = $year;
			}
		}

		return $latest;
	}//end latestYear()

	/**
	 * Schemas in humaniq's register; 0 when OpenRegister is off, the register is missing or cannot be read.
	 *
	 * @return integer
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
	 */
	private function schemaCount(): int {
		if ($this->settings->isOpenRegisterAvailable() === false || class_exists(self::REGISTER_MAPPER) === false) {
			return 0;
		}

		try {
			$register = $this->container->get(self::REGISTER_MAPPER)->find($this->settings->getRegisterSlug(), _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return 0;
		}

		$schemas = $register->getSchemas();
		if (is_array($schemas) === false) {
			return 0;
		}

		return count($schemas);
	}//end schemaCount()

	/**
	 * Whether Nextcloud's background jobs ran within the last hour.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
	 */
	private function backgroundJobsRun(): bool {
		$last = $this->appConfig->getValueInt('core', 'lastcron', 0);
		if ($last <= 0) {
			return false;
		}

		return ($this->time->getTime() - $last) <= self::CRON_MAX_AGE;
	}//end backgroundJobsRun()
}//end class
