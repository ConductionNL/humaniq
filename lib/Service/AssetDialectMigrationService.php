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
 * `Asset.status` is a documented, unavoidable partial case, with TWO
 * independent gates:
 *  1. Full-object schema validation runs on every `saveObject()` call
 *     regardless of the `_validation` parameter (that parameter gates a
 *     DIFFERENT, internal step -- the top-level enum/required check reads
 *     the SCHEMA's own persisted `hardValidation` flag instead, measured),
 *     so a payload whose `status` is still a legacy Dutch value is rejected
 *     even when the write does not otherwise touch `status` at all --
 *     `withAssetHardValidationDisabled()` is the narrowly-scoped,
 *     temporarily-toggled-and-restored fallback this forces for the
 *     category/field write (see `migrateAssets()`).
 *  2. OpenRegister's `LifecycleValidationListener` rejects a direct
 *     old-value -> new-value status write outright -- the schema's
 *     `x-openregister-lifecycle` block only declares `from` states in the
 *     NEW dialect, so no declared transition has an old-dialect `from`
 *     entry, regardless of write order, `_validation`, `silent`, or
 *     `SystemOperationContext` (none of which the listener consults; it
 *     fires on every `ObjectUpdatingEvent`, unconditionally, before the
 *     write) -- and gate 1's bypass does not help here, because it only
 *     relaxes schema validation, not this separate, unconditional listener.
 * Every OTHER field still migrates on the same object; the status attempt
 * is caught and recorded as skipped-with-reason using the listener's own
 * rejection message. Both are OpenRegister gaps (no migration-mode bypass
 * for the lifecycle guard; full-object validation with no way to validate
 * only the changed fields), not an hrmq defect -- worth filing against
 * OpenRegister, recorded here so the next author does not re-derive them.
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
	 * Old Dutch `Asset.category` value -> renamed value (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSET_CATEGORY_MAP = [
		'telefoon'    => 'phone',
		'voertuig'    => 'vehicle',
		'gereedschap' => 'tool',
		'toegangspas' => 'accessPass',
		'kleding'     => 'clothing',
		'overig'      => 'other',
	];

	/**
	 * Old Dutch `Asset.status` value -> renamed value (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSET_STATUS_MAP = [
		'beschikbaar' => 'available',
		'uitgegeven'  => 'issued',
		'ingenomen'   => 'checkedIn',
		'afgeschreven' => 'writtenOff',
	];

	/**
	 * Current schema `Asset.category` enum (a row already showing one of
	 * these values needs no category rewrite).
	 *
	 * @var string[]
	 */
	private const ASSET_CATEGORY_CURRENT = ['laptop', 'phone', 'vehicle', 'tool', 'accessPass', 'clothing', 'other'];

	/**
	 * Current schema `Asset.status` enum.
	 *
	 * @var string[]
	 */
	private const ASSET_STATUS_CURRENT = ['available', 'issued', 'checkedIn', 'writtenOff'];

	/**
	 * Old Dutch `Asset` field name -> renamed field name (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSET_FIELD_MAP = [
		'kenteken'      => 'licencePlate',
		'serienummer'   => 'serialNumber',
		'aanschafdatum' => 'purchaseDate',
		'aanschafwaarde' => 'purchaseValue',
	];

	/**
	 * Old Dutch `AssetAssignment` field name -> renamed field name (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSIGNMENT_FIELD_MAP = [
		'uitgifteDatum'     => 'issuedOn',
		'innameDatum'       => 'returnedOn',
		'uitgifteBonSigned' => 'issueReceiptSigned',
		'eigenBijdrage'     => 'employeeContribution',
	];

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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

			$mapped = $this->mapAssetRow($row);

			if ($mapped['hardSkipReason'] !== null) {
				$report['skipped']++;
				$report['skipReasons'][] = ['id' => $id, 'reason' => $mapped['hardSkipReason']];
				continue;
			}

			$rowWritten = false;

			if ($mapped['nonStatusChanged'] === true) {
				try {
					$this->saveAssetBase($mapped['withoutStatusChange'], $id);
					$rowWritten = true;
				} catch (\Throwable $e) {
					// The row's status still holds a legacy value the CURRENT
					// schema's enum no longer accepts. OpenRegister validates
					// the WHOLE object on every saveObject() call regardless
					// of `_validation` (that parameter gates a different,
					// internal step -- the top-level enum/required check
					// reads the SCHEMA's own persisted `hardValidation` flag,
					// not a per-call override, measured), so this write is
					// rejected purely because of a field deliberately left
					// untouched here (status is handled separately below;
					// writing it directly hits the lifecycle guard instead --
					// class docblock). Retry exactly once with the Asset
					// schema's hard validation narrowly and temporarily
					// disabled, restored in a finally block even on
					// exception (self::withAssetHardValidationDisabled()).
					// This is an OpenRegister gap -- full-object validation
					// plus an unconditional, un-bypassable lifecycle guard
					// makes a legacy-invalid enum value otherwise permanently
					// unwritable through the API -- not a routine migration
					// step: it engages only for a row still carrying an
					// old-dialect status, and is a no-op on every later run
					// once that is fixed (nonStatusChanged becomes false).
					try {
						$this->withAssetHardValidationDisabled(function () use ($mapped, $id) {
							$this->saveAssetBase($mapped['withoutStatusChange'], $id);
						});
						$rowWritten = true;
					} catch (\Throwable $retry) {
						$report['skipped']++;
						$report['skipReasons'][] = [
							'id' => $id,
							'reason' => 'category/field rewrite failed even with hard validation temporarily relaxed: ' . $retry->getMessage(),
						];
						// A failed base write means the status-only follow-up
						// below would be writing on top of an unknown state --
						// skip it for this row and move on.
						continue;
					}
				}//end try
			}//end if

			if ($mapped['statusChanged'] === true) {
				try {
					$this->objectService()->saveObject(
						object: $this->stripSelf($mapped['final']),
						register: $this->register(),
						schema: 'Asset',
						uuid: $id,
						_rbac: false,
						_multitenancy: false
					);
					$rowWritten = true;
				} catch (\Throwable $e) {
					$report['skipped']++;
					$report['skipReasons'][] = [
						'id' => $id,
						'reason' => sprintf(
							'status %s -> %s blocked by OpenRegister lifecycle guard: %s',
							(string)($row['status'] ?? ''),
							(string)($mapped['final']['status'] ?? ''),
							$e->getMessage()
						),
					];
				}
			}//end if

			if ($rowWritten === true) {
				$report['rewritten']++;
			} elseif ($mapped['nonStatusChanged'] === false && $mapped['statusChanged'] === false) {
				$report['alreadyCurrent']++;
			}
		}//end foreach

		return $report;

	}//end migrateAssets()

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

			$mapped = $this->mapFieldRenames($row, self::ASSIGNMENT_FIELD_MAP);

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
	 * Compute the migrated shape of one `Asset` row, split into a
	 * status-untouched write (category + field renames, always safe) and
	 * the full target shape including the translated status (may be
	 * rejected by the lifecycle guard -- see class docblock).
	 *
	 * @param array<string, mixed> $row The raw Asset row.
	 *
	 * @return array{hardSkipReason: string|null, nonStatusChanged: bool, statusChanged: bool, withoutStatusChange: array<string, mixed>, final: array<string, mixed>}
	 */
	private function mapAssetRow(array $row): array {
		$withoutStatusChange = $row;
		$nonStatusChanged = false;
		$hardSkipReasons = [];

		// category.
		$category = $row['category'] ?? null;
		if (is_string($category) === true && $category !== '') {
			if (isset(self::ASSET_CATEGORY_MAP[$category]) === true) {
				$withoutStatusChange['category'] = self::ASSET_CATEGORY_MAP[$category];
				$nonStatusChanged = true;
			} elseif (in_array($category, self::ASSET_CATEGORY_CURRENT, true) === false) {
				$hardSkipReasons[] = "unrecognised category value '" . $category . "'";
			}
		}

		// status (translated into $final only -- left untouched in
		// $withoutStatusChange, see class docblock).
		$statusChanged = false;
		$newStatus = null;
		$status = $row['status'] ?? null;
		if (is_string($status) === true && $status !== '') {
			if (isset(self::ASSET_STATUS_MAP[$status]) === true) {
				$statusChanged = true;
				$newStatus = self::ASSET_STATUS_MAP[$status];
			} elseif (in_array($status, self::ASSET_STATUS_CURRENT, true) === false) {
				$hardSkipReasons[] = "unrecognised status value '" . $status . "'";
			}
		}

		// Field renames (kenteken/serienummer/aanschafdatum/aanschafwaarde).
		$fieldResult = $this->applyFieldRenames($withoutStatusChange, self::ASSET_FIELD_MAP);
		if ($fieldResult['hardSkipReason'] !== null) {
			$hardSkipReasons[] = $fieldResult['hardSkipReason'];
		}

		if ($fieldResult['changed'] === true) {
			$withoutStatusChange = $fieldResult['row'];
			$nonStatusChanged = true;
		}

		// aanschafdatum carried a datetime ("YYYY-MM-DD HH:MM:SS"); purchaseDate
		// is `format: date`. Normalise, but only when the rename actually fired.
		if (isset($withoutStatusChange['purchaseDate']) === true && is_string($withoutStatusChange['purchaseDate']) === true) {
			$withoutStatusChange['purchaseDate'] = substr($withoutStatusChange['purchaseDate'], 0, 10);
		}

		// aanschafwaarde came through as a numeric string ("1249.00");
		// purchaseValue is `type: number`.
		if (isset($withoutStatusChange['purchaseValue']) === true && is_numeric($withoutStatusChange['purchaseValue']) === true) {
			$withoutStatusChange['purchaseValue'] = (float)$withoutStatusChange['purchaseValue'];
		}

		$final = $withoutStatusChange;
		if ($statusChanged === true) {
			$final['status'] = $newStatus;
		}

		return [
			'hardSkipReason' => ($hardSkipReasons === [] ? null : implode('; ', $hardSkipReasons)),
			'nonStatusChanged' => $nonStatusChanged,
			'statusChanged' => $statusChanged,
			'withoutStatusChange' => $withoutStatusChange,
			'final' => $final,
		];

	}//end mapAssetRow()

	/**
	 * Apply a set of old-field -> new-field renames to a row. A row
	 * carrying BOTH the old and new field with DIFFERENT values is not
	 * guessed at -- it is reported via `hardSkipReason` and left untouched;
	 * with EQUAL values, the old (redundant) key is simply dropped.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, string> $fieldMap Old field name => new field name.
	 *
	 * @return array{row: array<string, mixed>, changed: bool, hardSkipReason: string|null}
	 */
	private function mapFieldRenames(array $row, array $fieldMap): array {
		$result = $this->applyFieldRenames($row, $fieldMap);
		return [
			'row' => $result['row'],
			'changed' => $result['changed'],
			'hardSkipReason' => $result['hardSkipReason'],
		];

	}//end mapFieldRenames()

	/**
	 * Shared field-rename engine used by both `mapAssetRow()` and
	 * `mapFieldRenames()`.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, string> $fieldMap Old field name => new field name.
	 *
	 * @return array{row: array<string, mixed>, changed: bool, hardSkipReason: string|null}
	 */
	private function applyFieldRenames(array $row, array $fieldMap): array {
		$updated = $row;
		$changed = false;
		$conflicts = [];

		foreach ($fieldMap as $old => $new) {
			if (array_key_exists($old, $row) === false || $row[$old] === null) {
				// A null old-name key is an ABSENT value, not a value to
				// migrate -- OpenRegister objects can carry a null-valued
				// key left over from an earlier schema version that once
				// declared it (measured: `kenteken`/`aanschafdatum`/
				// `aanschafwaarde`/`uitgifteDatum` all appear as explicit
				// nulls on rows that never held Dutch-dialect data in the
				// first place). Treating a null as "present" would report a
				// false conflict against a populated new-name field and
				// block an already-current row from ever reaching
				// alreadyCurrent.
				continue;
			}

			$oldValue = $row[$old];
			$newPresent = array_key_exists($new, $row) === true && $row[$new] !== null;

			if ($newPresent === true) {
				$newValue = $row[$new];
				if ($this->valuesEquivalent($oldValue, $newValue) === true) {
					// Both names hold the same value -- this field's job is
					// done, and there is nothing left to WRITE: OpenRegister
					// never generates a SET clause for a property the
					// CURRENT schema no longer declares, so neither omitting
					// the retired key NOR sending it as an explicit null
					// clears it (both measured against the live instance --
					// orchestrator review, 2026-08-19, Defect C's first
					// "fix" assumed an explicit null would be written
					// through; it is not even looked at). The retired key's
					// stale value is permanent, harmless debris no schema or
					// consumer reads any more -- treating its mere presence
					// as still-unfinished work would make this field retry
					// forever, which is what actually broke idempotency.
					continue;
				}

				$conflicts[] = sprintf(
					"both '%s' (%s) and '%s' (%s) present with different values, refusing to guess",
					$old,
					json_encode($oldValue),
					$new,
					json_encode($newValue)
				);
				continue;
			}//end if

			// Plain rename: the new name is not populated yet, so writing it
			// is real, achievable progress -- the retired key is left
			// exactly as-is in the payload (see the branch above for why
			// touching it at all would be a wasted write).
			$updated[$new] = $oldValue;
			$changed = true;
		}//end foreach

		return [
			'row' => $updated,
			'changed' => $changed,
			'hardSkipReason' => ($conflicts === [] ? null : implode('; ', $conflicts)),
		];

	}//end applyFieldRenames()

	/**
	 * Loose-but-safe equivalence for a duplicate old/new field pair (a
	 * legacy string boolean and a real boolean, or a numeric string and a
	 * float, should count as "already the same value").
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool
	 */
	private function valuesEquivalent(mixed $a, mixed $b): bool {
		if ($a === $b) {
			return true;
		}

		if (is_numeric($a) === true && is_numeric($b) === true) {
			return (float)$a === (float)$b;
		}

		if (is_bool($a) === true || is_bool($b) === true) {
			return (bool)$a === (bool)$b;
		}

		// A datetime string ("YYYY-MM-DD HH:MM:SS", `aanschafdatum`'s old
		// stored precision) and a date-only string ("YYYY-MM-DD",
		// `purchaseDate`'s `format: date`) for the same calendar day are the
		// same value at a different precision, not a conflict -- measured
		// against the live instance (every pre-existing Asset date was
		// always midnight).
		if (is_string($a) === true && is_string($b) === true
			&& preg_match('/^\d{4}-\d{2}-\d{2}/', $a) === 1
			&& preg_match('/^\d{4}-\d{2}-\d{2}/', $b) === 1
		) {
			return substr($a, 0, 10) === substr($b, 0, 10);
		}

		return (string)$a === (string)$b;

	}//end valuesEquivalent()

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
	 * The category/field-only Asset write (status deliberately left as
	 * whatever the caller passed -- unchanged on the normal path, see
	 * `migrateAssets()`).
	 *
	 * @param array<string, mixed> $object The row to save (already carrying its target category/field values).
	 * @param string $id The object's uuid.
	 *
	 * @return void
	 */
	private function saveAssetBase(array $object, string $id): void {
		$this->objectService()->saveObject(
			object: $this->stripSelf($object),
			register: $this->register(),
			schema: 'Asset',
			uuid: $id,
			_rbac: false,
			_multitenancy: false,
			_validation: false
		);

	}//end saveAssetBase()

	/**
	 * Run `$operation` with the Asset schema's `hardValidation` narrowly and
	 * temporarily disabled, restored in a `finally` block even when
	 * `$operation` throws. See the call site in `migrateAssets()` for why
	 * this exists: OpenRegister validates a saveObject() payload in full
	 * regardless of `_validation`, so a row still holding one legacy-invalid
	 * enum value cannot be written at all -- not even to fix an unrelated
	 * field. Scoped to the single retry that needs it; every other write in
	 * this service never touches this method.
	 *
	 * @param callable $operation The write to attempt with validation relaxed.
	 *
	 * @return void
	 *
	 * @throws \Throwable Whatever `$operation` throws, after validation is restored.
	 */
	private function withAssetHardValidationDisabled(callable $operation): void {
		$schema = $this->objectService()->setRegister($this->register())->setSchema('Asset')->getCurrentSchemaEntity();
		if ($schema === null) {
			// No schema entity to toggle -- let the operation run as-is; its
			// own exception (if any) propagates unchanged.
			$operation();
			return;
		}

		$original = $schema->getHardValidation();
		if ($original === false) {
			// Already permissive -- nothing to toggle, nothing to restore.
			$operation();
			return;
		}

		$this->setAssetHardValidation($schema, false);
		try {
			$operation();
		} finally {
			$this->setAssetHardValidation($schema, true);
		}

	}//end withAssetHardValidationDisabled()

	/**
	 * @param mixed $schema The OpenRegister `Schema` entity (from `getCurrentSchemaEntity()`).
	 * @param bool $enabled The `hardValidation` value to persist.
	 *
	 * @return void
	 */
	private function setAssetHardValidation(mixed $schema, bool $enabled): void {
		$schema->setHardValidation($enabled);

		try {
			$this->schemaMapper()->update($schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AssetDialectMigrationService: could not set Asset schema hardValidation=' . ($enabled === true ? 'true' : 'false') . ': ' . $e->getMessage()
			);
			throw $e;
		}

	}//end setAssetHardValidation()

	/**
	 * @return mixed The OpenRegister SchemaMapper.
	 */
	private function schemaMapper(): mixed {
		if (class_exists('OCA\OpenRegister\Db\SchemaMapper') === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');

	}//end schemaMapper()

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
