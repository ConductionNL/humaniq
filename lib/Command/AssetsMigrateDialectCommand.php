<?php

/**
 * Assets Migrate Dialect Command
 *
 * `occ humaniq:assets:migrate-dialect` -- on-demand, humaniq-only re-run of the
 * Asset/AssetAssignment dialect rewrite (hrmq-asset-fleet-merge, tasks.md
 * section 13). The same rewrite also runs unconditionally as the
 * `MigrateAssetDialect` post-migration repair step on every app upgrade and
 * every `occ maintenance:repair` -- this command exists for an admin who
 * wants to re-run just this migration (e.g. to check its report) without
 * triggering every other app's post-migration steps. Idempotent; prints
 * per-schema counts (inspected/rewritten/alreadyCurrent/skipped) and the
 * reason for every skipped row.
 *
 * @category Command
 * @package  OCA\Humaniq\Command
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\AssetDialectMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that re-runs the Asset/AssetAssignment dialect migration on demand.
 */
class AssetsMigrateDialectCommand extends Command {

	/**
	 * @param AssetDialectMigrationService $migrationService The migration.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
	 */
	public function __construct(
		private readonly AssetDialectMigrationService $migrationService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
	 */
	protected function configure(): void {
		$this->setName('humaniq:assets:migrate-dialect')
			->setDescription('Rewrite pre-existing Asset/AssetAssignment objects from the old Dutch dialect to the renamed one (idempotent).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input. Unused: this command takes no
	 *        arguments or options -- the migration is idempotent, so there is
	 *        nothing to parameterise and nothing a flag would usefully change.
	 *        The parameter cannot be dropped; Symfony's Command base class
	 *        fixes the signature.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->migrationService->migrate();

		$output->writeln('<info>Humaniq Asset/AssetAssignment dialect migration</info>');

		foreach ($report as $schema => $stat) {
			$output->writeln(sprintf(
				'  %-16s inspected=%d rewritten=%d alreadyCurrent=%d skipped=%d',
				$schema,
				$stat['inspected'],
				$stat['rewritten'],
				$stat['alreadyCurrent'],
				$stat['skipped']
			));

			foreach ($stat['skipReasons'] as $skip) {
				$output->writeln(sprintf('    skipped %s: %s', $skip['id'], $skip['reason']));
			}
		}

		return 0;
	}//end execute()

}//end class
