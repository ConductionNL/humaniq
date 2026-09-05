<?php

/**
 * Renames this app's OpenRegister schema SLUGS in place, before the import.
 *
 * WHY A REPAIR STEP AT ALL. The same reason {@see MigrateRegisterSlug} needs
 * one, one level down: `ImportHandler` resolves a schema by
 * `(application, slug)` and its not-found branch is not an error path, it is
 * the "create a new one" path. Shipping a renamed slug in the register JSON
 * without renaming the row first therefore renames nothing. The import finds no
 * match, CREATES A SECOND, EMPTY SCHEMA, and the app addresses that one from
 * then on. Every stored object stays behind on the old row, reachable by
 * nothing. Nothing errors. The collection simply renders empty.
 *
 * WHY IT MOVES NO DATA. An object is bound to its schema by NUMERIC id, not by
 * slug: `_schema` in every shard table, and the tables are named
 * `oc_openregister_table_<registerId>_<schemaId>`. There is no slug anywhere in
 * the physical layout, so this is one column on one row and every object,
 * table and link follows it untouched.
 *
 * WHY `GeneratedDocument` MOVES. A schema slug is global per organisation and
 * two apps declared one: filinq's `generatedDocument` (a rendered document:
 * caseId, templateId, format, dataRefs) and this app's `GeneratedDocument` (the
 * HR record of what was rendered for whom: employeeId, contractId, payslipId,
 * jaaropgaafId, documentType). `SchemaMapper::find()` matches `LOWER(slug)`, so
 * whichever row is reached first answers for both.
 *
 * They are not the same entity and neither should be retired. This app already
 * delegates the RENDERING to filinq — `OfferLetterService` resolves
 * `OCA\DocuDesk\Service\DocumentService` by FQCN — so "only filinq generates
 * documents" is already true here; what collided was only the name of the
 * record. Namespacing it is the same move larpinq made for `skill`, `item` and
 * `event` (`larping_*`), and for the same reason.
 *
 * ORDERING IS LOAD-BEARING. It runs AFTER MigrateSchemaApplicationId, which
 * moves this app's schemas onto the `humaniq` application id — this step scopes
 * its UPDATE to that id, so a run before it would match nothing. It runs BEFORE
 * InitializeRegister, which is the thing that forks.
 *
 * NON-DESTRUCTIVE AND IDEMPOTENT. It renames only when the old slug is present
 * and the new one is not; a second run finds nothing to do and says so. It
 * refuses rather than merges when both exist, because two rows sharing
 * (application, slug) means the lower id silently wins every lookup, and
 * choosing between them is a decision about data. It never throws: under
 * `<install>` an escaping exception aborts the install and the app never
 * enables at all.
 *
 * @category  Repair
 * @package   OCA\Humaniq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\AppInfo\Application;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames schema slugs this app owns, ahead of the register import.
 */
class MigrateSchemaSlug implements IRepairStep {

	/**
	 * Old slug => new slug, for schemas this app owns.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'GeneratedDocument' => 'HrGeneratedDocument',
	];

	/**
	 * Wire the database and the logger.
	 *
	 * @param IDBConnection            $db        Database connection.
	 * @param LoggerInterface          $logger    Logger.
	 * @param MigrateRegisterSlugDecisions $decisions Rename/refuse planner.
	 *
	 * @spec exclude No canonical spec covers the schema-slug namespacing; it is
	 *  a migration for a fleet-wide slug collision, not a product requirement.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly MigrateRegisterSlugDecisions $decisions,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string The step name.
	 *
	 * @spec exclude No canonical spec covers the schema-slug namespacing; it is
	 *  a migration for a fleet-wide slug collision, not a product requirement.
	 */
	public function getName(): string {
		return 'Namespace Humaniq schema slugs that collide across the fleet';
	}//end getName()

	/**
	 * Rename each mapped slug whose target is free.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the schema-slug namespacing; it is
	 *  a migration for a fleet-wide slug collision, not a product requirement.
	 */
	public function run(IOutput $output): void {
		$existing = $this->slugsOfThisApp();
		if ($existing === null) {
			$output->info('MigrateSchemaSlug: could not read schemas; nothing done.');
			return;
		}

		$plan = $this->decisions->plan(map: self::SLUG_MAP, existing: $existing);

		foreach ($plan['refused'] as $old => $why) {
			$this->logger->warning(
				'MigrateSchemaSlug: '.$why.'; renaming neither.',
				['old' => $old]
			);
		}

		$renamed = 0;
		foreach ($plan['renames'] as $old => $new) {
			if ($this->renameSlug(old: $old, new: $new) === true) {
				$renamed++;
			}
		}

		$output->info(
			sprintf(
				'MigrateSchemaSlug: %d schema slug(s) renamed, %d refused.',
				$renamed,
				count($plan['refused'])
			)
		);
	}//end run()

	/**
	 * The slugs this app's schemas currently carry.
	 *
	 * Returns null on a read failure, which the caller treats as "do nothing".
	 * An empty list and a failed read must not look the same: an empty list says
	 * there is nothing to rename, and a failed read says nothing at all.
	 *
	 * @return array<int, string>|null The slugs, or null when unreadable.
	 */
	private function slugsOfThisApp(): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE application = ?',
				[Application::APP_ID]
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateSchemaSlug: could not read this app\'s schemas; skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->decisions->slugsFrom(rows: $rows);
	}//end slugsOfThisApp()

	/**
	 * Rename one schema slug.
	 *
	 * Scoped to `(application, slug)` so it can never touch a row belonging to
	 * another app that happens to share the slug — which is the entire reason
	 * this step exists.
	 *
	 * @param string $old The current slug.
	 * @param string $new The slug to move to.
	 *
	 * @return bool True when the row was updated.
	 */
	private function renameSlug(string $old, string $new): bool {
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE application = ? AND slug = ?',
				[$new, Application::APP_ID, $old]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateSchemaSlug: could not rename schema slug.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		$this->logger->info(
			'MigrateSchemaSlug: renamed schema slug.',
			['old' => $old, 'new' => $new]
		);

		return true;
	}//end renameSlug()

}//end class
