<?php

/**
 * Asset Dialect Migration Service
 *
 * hrmq-asset-fleet-merge (tasks.md section 13, blocking defect): rewrites
 * pre-existing `Asset`/`AssetAssignment` objects from the retired Dutch
 * dialect to the renamed one. OpenRegister's register-config seed import is
 * create-only -- it never patches an object that already exists -- so the
 * schema/seed/rule rename alone left every object created before this change
 * carrying the old field names and enum values indefinitely. That is not a
 * cosmetic gap: `nl-asset-voertuig-fiscale-velden-compleet` matches
 * `category === "vehicle"` literally, so an old-dialect `category: voertuig`
 * Asset silently exempts itself from a rule that should flag it.
 *
 * `category`/`status` enum values and the `kenteken`/`serienummer`/
 * `aanschafdatum`/`aanschafwaarde` (Asset) and `uitgifteDatum`/`innameDatum`/
 * `uitgifteBonSigned`/`eigenBijdrage` (AssetAssignment) field NAMES are
 * translated per design.md D1's table -- the CURRENT (renamed) name gets the
 * value; the RETIRED name is left exactly as read, never touched. That is
 * not a shortcut: a retired name is no longer declared by the current
 * schema, and OpenRegister's magic-table UPDATE generates a SET clause only
 * for properties the schema still declares (`SaveObject::
 * fillMissingSchemaPropertiesWithNull()`, measured against the live
 * instance to hold for BOTH omitting the retired key from the payload AND
 * sending it as an explicit null -- neither reaches the underlying column).
 * A retired key's stale value is therefore permanent, harmless debris no
 * schema or consumer reads any more; re-attempting to clear it on every run
 * would itself be the idempotency bug (a wasted write that never converges).
 * Idempotent: a row where every reachable field already holds its migrated
 * value is left untouched, even when a retired key is still sitting beside
 * it. Never deletes an object, never guesses at an unrecognised or
 * conflicting value -- both are counted as skipped, with the value(s)
 * recorded as the reason.
 *
 * `Asset.status` has TWO independent gates in OpenRegister, and both are
 * cleared by ONE write of the fully-migrated payload -- with validation
 * fully on:
 *  1. Full-object schema validation runs on every `saveObject()` call
 *     regardless of the `_validation` parameter (that parameter gates a
 *     DIFFERENT, internal step -- the top-level enum/required check reads
 *     the SCHEMA's own persisted `hardValidation` flag instead, measured).
 *     It validates the PAYLOAD, so sending the new English status satisfies
 *     it. A two-phase write that rewrites fields first, leaving the legacy
 *     status in the payload untouched, is rejected on that untouched field
 *     -- which is why this service writes everything at once.
 *  2. OpenRegister's `LifecycleValidationListener` needs a DECLARED
 *     transition whose `from` carries the old value. It consults neither
 *     `_validation`, `silent`, nor `SystemOperationContext`; it fires on
 *     every `ObjectUpdatingEvent`, unconditionally, before the write. The
 *     Asset schema therefore declares four migration-only
 *     `migrateLegacyStatus_*` transitions -- the EXPAND half of an
 *     expand/contract enum migration. They are removed (the CONTRACT half)
 *     once no row holds a legacy status; nothing can re-enter one, because
 *     the legacy values are not in the enum.
 *
 * An earlier revision instead toggled the Asset schema's persisted
 * `hardValidation` flag off around the write and restored it in a `finally`.
 * It worked, and it is recorded here because the measurements behind it are
 * correct and hard-won -- but the flag is GLOBAL, so every concurrent writer
 * of Asset skipped validation while it was off, and a fatal or SIGKILL
 * between toggle and restore would have left validation off permanently and
 * silently. Expand/contract needs no bypass at all.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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

namespace OCA\Hrmq\Service;

use OCA\Hrmq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Rewrites existing Asset/AssetAssignment objects from the old Dutch dialect
 * to the renamed one (idempotent; never deletes; skips rather than guesses).
 */
class AssetDialectMigrationService {

	/**
	 * Max objects loaded per schema for the migration (RuleAuditService::LIMIT precedent).
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 * @param AssetDialectMapper $mapper Pure old->new dialect row mapping.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly AssetDialectMapper $mapper = new AssetDialectMapper(),
	) {

	}//end __construct()

	/**
	 * Rewrite every existing `Asset`/`AssetAssignment` object from the old
	 * dialect to the new one. Idempotent; safe to call repeatedly.
	 *
	 * @return array<string, array<string, mixed>> Per-schema report: schema => {
	 *     inspected: int, rewritten: int, alreadyCurrent: int, skipped: int,
	 *     skipReasons: array<int, array{id: string, reason: string}>
	 * }. `rewritten` and `skipped` are NOT mutually exclusive for a row: a
	 * row can have its category/fields rewritten AND have its status
	 * skipped-with-reason on the same run (Asset.status, see class docblock).
	 *
	 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
	 */
	public function migrate(): array {
		return [
			'Asset'           => $this->migrateAssets(),
			'AssetAssignment' => $this->migrateAssignments(),
		];

	}//end migrate()

	/**
	 * Migrate every `Asset` row.
	 *
	 * @return array<string, mixed>
	 */
	private function migrateAssets(): array {
		$report = $this->emptyReport();

		foreach ($this->loadAll('Asset') as $row) {
			$report['inspected']++;
			$id = $this->idOf($row);
			if ($id === '') {
				$report['skipped']++;
				$report['skipReasons'][] = ['id' => '', 'reason' => 'row has no resolvable id/@self.id, skipped'];
				continue;
			}

			$mapped = $this->mapper->mapAssetRow($row);

			if ($mapped['hardSkipReason'] !== null) {
				$report['skipped']++;
				$report['skipReasons'][] = ['id' => $id, 'reason' => $mapped['hardSkipReason']];
				continue;
			}

			$this->writeAssetRow(id: $id, row: $row, mapped: $mapped, report: $report);
		}//end foreach

		return $report;

	}//end migrateAssets()

	/**
	 * Write one mapped Asset row and record the outcome on the report.
	 *
	 * ONE write, carrying the fully-migrated payload -- fields AND status
	 * together. That clears both of OpenRegister's gates with validation fully
	 * ON, which is why no bypass is needed:
	 *
	 *  1. Full-object schema validation reads the PAYLOAD, and the payload's
	 *     status is already the new English value, so the enum check passes.
	 *     The earlier two-phase approach failed precisely because its first
	 *     write left the legacy status in the payload untouched.
	 *  2. `LifecycleValidationListener` needs a DECLARED transition whose
	 *     `from` carries the old value. The schema declares four
	 *     migration-only `migrateLegacyStatus_*` transitions for exactly that
	 *     -- the expand half of an expand/contract enum migration, removed
	 *     again once no row holds a legacy status.
	 *
	 * This replaces a temporary global toggle of the Asset SCHEMA's persisted
	 * `hardValidation` flag, which relaxed validation for every concurrent
	 * writer of Asset while it was off, and which a fatal between toggle and
	 * restore would have left off permanently and silently.
	 *
	 * @param string               $id     The object uuid.
	 * @param array<string, mixed> $row    The row as read.
	 * @param array<string, mixed> $mapped The mapper's result for that row.
	 * @param array<string, mixed> $report The report, mutated in place.
	 *
	 * @return void
	 */
	private function writeAssetRow(string $id, array $row, array $mapped, array &$report): void {
		if ($mapped['nonStatusChanged'] === false && $mapped['statusChanged'] === false) {
			$report['alreadyCurrent']++;
			return;
		}

		try {
			$this->objectService()->saveObject(
				object: $this->stripSelf($mapped['final']),
				register: $this->register(),
				schema: 'Asset',
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
			$report['rewritten']++;
		} catch (\Throwable $e) {
			$report['skipped']++;
			$report['skipReasons'][] = [
				'id' => $id,
				'reason' => sprintf(
					'dialect rewrite failed (status %s -> %s): %s',
					(string)($row['status'] ?? ''),
					(string)($mapped['final']['status'] ?? ''),
					$e->getMessage()
				),
			];
		}

	}//end writeAssetRow()

	/**
	 * Migrate every `AssetAssignment` row (field renames only -- no enum,
	 * no lifecycle guard on this schema).
	 *
	 * @return array<string, mixed>
	 */
	private function migrateAssignments(): array {
		$report = $this->emptyReport();

		foreach ($this->loadAll('AssetAssignment') as $row) {
			$report['inspected']++;
			$id = $this->idOf($row);
			if ($id === '') {
				$report['skipped']++;
				$report['skipReasons'][] = ['id' => '', 'reason' => 'row has no resolvable id/@self.id, skipped'];
				continue;
			}

			$mapped = $this->mapper->mapAssignmentRow($row);

			if ($mapped['hardSkipReason'] !== null) {
				$report['skipped']++;
				$report['skipReasons'][] = ['id' => $id, 'reason' => $mapped['hardSkipReason']];
				continue;
			}

			if ($mapped['changed'] === false) {
				$report['alreadyCurrent']++;
				continue;
			}

			try {
				$this->objectService()->saveObject(
					object: $this->stripSelf($mapped['row']),
					register: $this->register(),
					schema: 'AssetAssignment',
					uuid: $id,
					_rbac: false,
					_multitenancy: false
				);
				$report['rewritten']++;
			} catch (\Throwable $e) {
				$report['skipped']++;
				$report['skipReasons'][] = ['id' => $id, 'reason' => 'field rewrite failed: ' . $e->getMessage()];
			}
		}//end foreach

		return $report;

	}//end migrateAssignments()

	/**
	 * @return array<string, mixed> An empty per-schema report shape.
	 */
	private function emptyReport(): array {
		return [
			'inspected' => 0,
			'rewritten' => 0,
			'alreadyCurrent' => 0,
			'skipped' => 0,
			'skipReasons' => [],
		];

	}//end emptyReport()

	/**
	 * Strip `@self` before a write (LeaveBuySellSettlementService::settle() precedent).
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function stripSelf(array $row): array {
		unset($row['@self']);
		return $row;

	}//end stripSelf()


	/**
	 * @param array<string, mixed> $row An object row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');

	}//end idOf()

	/**
	 * Load all objects of a schema (capped), as plain arrays. RBAC/
	 * multitenancy bypassed -- a migration must reach every object
	 * regardless of the caller's ambient scope.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT], false, false);
		} catch (\Throwable $e) {
			$this->logger->warning('AssetDialectMigrationService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;

	}//end loadAll()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. class_exists() rather
		// than SettingsService::isOpenRegisterAvailable(), because this class
		// does not inject SettingsService and adding a constructor dependency
		// purely to ask a yes/no question is the wrong trade. It answers the
		// same question the container would otherwise have answered fatally.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');

	}//end objectService()

	/**
	 * @return string The configured register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
		return $register === '' ? 'hrmq' : $register;

	}//end register()

}//end class
