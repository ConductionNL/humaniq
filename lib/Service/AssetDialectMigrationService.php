<?php

/**
 * Asset Dialect Migration Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * humaniq-asset-fleet-merge (tasks.md section 13, blocking defect): rewrites
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
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\RegisterSlugLookup;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\Humaniq\Support\ResolvesObjectServiceByClass;
use OCA\Humaniq\Support\KeepsReadableRows;

/**
 * Rewrites existing Asset/AssetAssignment objects from the old Dutch dialect
 * to the renamed one (idempotent; never deletes; skips rather than guesses).
 *
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
 */
class AssetDialectMigrationService {

	use ResolvesObjectServiceByClass;
	use KeepsReadableRows;

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
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
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
	 *                                             inspected: int, rewritten: int, alreadyCurrent: int, skipped: int,
	 *                                             skipReasons: array<int, array{id: string, reason: string}>
	 *                                             }. `rewritten` and `skipped` are NOT mutually exclusive for a row: a
	 *                                             row can have its category/fields rewritten AND have its status
	 *                                             skipped-with-reason on the same run (Asset.status, see class docblock).
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	public function migrate(): array {
		// Resolved ONCE, here, and deliberately OUTSIDE every try below.
		// `register()` raises when this instance carries no humaniq register,
		// and `loadAll()` plus both write paths wrap their work in
		// `catch (\Throwable)` — so calling it inside one of those turned the
		// raise into a logged warning and a per-row "skipped" reason, and the
		// migration reported a completed run that had moved nothing. Caught by
		// AbsentRegisterFailureDirectionTest before this shipped.
		$register = $this->register();

		return [
			'Asset' => $this->migrateAssets($register),
			'AssetAssignment' => $this->migrateAssignments($register),
		];

	}//end migrate()

	/**
	 * Migrate every `Asset` row.
	 *
	 * @param string $register The resolved register slug, from migrate().
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	private function migrateAssets(string $register): array {
		$report = $this->emptyReport();

		foreach ($this->loadAll('Asset', $register) as $row) {
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

			$this->writeAssetRow(candidate: ['id' => $id, 'row' => $row, 'mapped' => $mapped, 'register' => $register], report: $report);
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
	 * @param array{id: string, row: array<string, mixed>, mapped: array<string, mixed>, register: string} $candidate The object uuid, the row as read, the mapper's result for it and the register slug.
	 * @param array<string, mixed> $report The report, mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	private function writeAssetRow(array $candidate, array &$report): void {
		['id' => $id, 'row' => $row, 'mapped' => $mapped, 'register' => $register] = $candidate;
		if ($mapped['nonStatusChanged'] === false && $mapped['statusChanged'] === false) {
			$report['alreadyCurrent']++;
			return;
		}

		try {
			$this->objectService()->saveObject(
				object: $this->stripSelf($mapped['final']),
				register: $register,
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
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	private function migrateAssignments(string $register): array {
		$report = $this->emptyReport();

		foreach ($this->loadAll('AssetAssignment', $register) as $row) {
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
					register: $register,
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
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
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
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	private function stripSelf(array $row): array {
		unset($row['@self']);
		return $row;
	}//end stripSelf()

	/**
	 * @param array<string, mixed> $row An object row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
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
	 *
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	private function loadAll(string $schema, string $register): array {
		try {
			$rows = $this->objectService()
				->setRegister($register)
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT], false, false);
		} catch (\Throwable $e) {
			$this->logger->warning('AssetDialectMigrationService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * The slug this instance's humaniq register answers to.
	 *
	 * This used to end `return $register === '' ? 'hrmq' : $register;` under a
	 * note saying the `hrmq` fallback was frozen across the rename — while the
	 * `getValueString()` default beside it already said `humaniq`. So the frozen
	 * branch was reachable only for a config value stored as the empty string,
	 * and every ordinary instance took the canonical default instead. On an
	 * instance that has not yet run `MigrateRegisterSlug` the register is still
	 * `hrmq`, and OpenRegister does not raise for a register that is not there.
	 * See ConductionNL/openregister#3579.
	 *
	 * This one WRITES, with `_rbac: false` and `_multitenancy: false`. A slug
	 * nothing answers to would point a privileged migration at a register that
	 * is not there, and a migration that reports success having moved nothing is
	 * worse than one that stops. It raises instead.
	 *
	 * @return string The slug to use.
	 *
	 * @throws RuntimeException When this instance carries no humaniq register
	 *                          under any of its known slugs.
	 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
	 */
	private function register(): string {
		$slug = (new RegisterSlugLookup($this->container, $this->appConfig))->slugOrNull();
		if ($slug === null) {
			$message = 'Het humaniq-register is niet gevonden op deze instance. Voer de humaniq-reparatiestap '
				. 'uit of stel het register in bij de humaniq-instellingen.';
			$this->logger->error(static::class . ': ' . $message);
			throw new RuntimeException($message);
		}

		return $slug;
	}//end register()
}//end class
