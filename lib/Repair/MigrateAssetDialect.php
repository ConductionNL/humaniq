<?php

/**
 * Hrmq Migrate Asset Dialect Repair Step
 *
 * hrmq-asset-fleet-merge (tasks.md section 13, blocking defect): the
 * Asset/AssetAssignment schema rename shipped without a data migration.
 * OpenRegister's register-config seed import is create-only, so it never
 * patched an object created before the rename -- those objects still carry
 * the old Dutch field names/enum values, silently exempting themselves from
 * rules that match the new dialect literally (`nl-asset-voertuig-fiscale-
 * velden-compleet` matching `category === "vehicle"` never saw a
 * `category: voertuig` row).
 *
 * Registered as a `post-migration` repair step (appinfo/info.xml), which
 * `\OC_App::upgradeApp()` runs unconditionally on every app upgrade AND
 * which `occ maintenance:repair` re-runs for every enabled app (core's
 * `Repair` command loads each app's `post-migration` steps) -- unlike
 * `live-migration` steps, which only fire once, queued as a background job,
 * on the app-version transition itself. `post-migration` is chosen so an
 * admin can force a re-run on demand (`occ maintenance:repair`) without
 * needing an app-version bump, on top of it firing automatically on a real
 * upgrade. `AssetDialectMigrationService::migrate()` is idempotent, so
 * running it on every `occ maintenance:repair` is safe -- see the
 * `occ hrmq:assets:migrate-dialect` command for a narrower, hrmq-only
 * re-run that does not also run every other app's post-migration steps.
 *
 * @category Repair
 * @package  OCA\Hrmq\Repair
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
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Repair;

use OCA\Hrmq\Service\AssetDialectMigrationService;
use OCA\Hrmq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rewrites pre-existing Asset/AssetAssignment objects from the old Dutch
 * dialect to the renamed one, on every upgrade and every `occ
 * maintenance:repair`.
 */
class MigrateAssetDialect implements IRepairStep {

	/**
	 * @param AssetDialectMigrationService $migrationService The migration.
	 * @param SettingsService $settingsService Availability check.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly AssetDialectMigrationService $migrationService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Rewrite pre-existing hrmq Asset/AssetAssignment objects to the renamed English dialect';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not installed or enabled. Skipping Asset dialect migration.');
			return;
		}

		try {
			$report = $this->migrationService->migrate();
		} catch (\Throwable $e) {
			$output->warning('Could not migrate Asset/AssetAssignment dialect: ' . $e->getMessage());
			$this->logger->error('Hrmq: Asset dialect migration failed', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($report as $schema => $stat) {
			$output->info(sprintf(
				'%s: inspected=%d rewritten=%d alreadyCurrent=%d skipped=%d',
				$schema,
				$stat['inspected'],
				$stat['rewritten'],
				$stat['alreadyCurrent'],
				$stat['skipped']
			));

			foreach ($stat['skipReasons'] as $skip) {
				$output->info(sprintf('  skipped %s: %s', $skip['id'], $skip['reason']));
			}
		}

	}//end run()

}//end class
