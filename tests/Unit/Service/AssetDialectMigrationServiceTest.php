<?php

/**
 * Unit tests for AssetDialectMigrationService.
 *
 * Pins the hrmq-asset-fleet-merge blocking-defect fix (tasks.md section 13):
 * an old-dialect `Asset`/`AssetAssignment` row is rewritten to the new
 * dialect; a row already in the new dialect is untouched (idempotent),
 * INCLUDING when an old field name survives only as a null-valued key
 * alongside its populated renamed replacement (a null is an absent value,
 * not a conflicting one -- orchestrator review, 2026-08-19: the first cut of
 * this fix read a null-valued old key as "present" and reported the row
 * skipped instead of already-current); an unrecognised enum value, and a
 * field pair carrying two DIFFERENT non-null values for the old and new
 * name, are both skipped-with-reason rather than guessed; and `Asset.status`
 * -- the one field OpenRegister's lifecycle guard refuses to let this
 * migration rewrite (no declared transition has an old-dialect `from`) -- is
 * left in place with the guard's own rejection recorded as the skip reason,
 * on every run, without escalating.
 *
 * Drives the service through a fake ObjectService double (the
 * RuleAuditServiceTest/WkrServiceTest shape) whose `saveObject()` simulates
 * TWO independent OpenRegister gates a real Asset write can hit, in the
 * order the real API applies them:
 *  1. Full-object schema validation, unconditional on the `_validation`
 *     parameter (that parameter does not gate the top-level enum/required
 *     check -- ObjectService::validateObjectIfRequired() reads the SCHEMA's
 *     own persisted `hardValidation` flag, measured): a payload whose
 *     `status` is a legacy Dutch value is rejected outright, even when the
 *     write does not otherwise touch `status` at all -- exactly the trap
 *     that makes fixing `category` alone on an old-dialect row impossible
 *     without ALSO working around this (orchestrator review, 2026-08-19:
 *     the first cut of this fix wrote category/fields with `status` left
 *     unchanged and assumed `_validation: false` would let that through; it
 *     does not, and the mapping test that should have caught it used a fake
 *     that never simulated schema validation in the first place).
 *  2. OpenRegister's `LifecycleValidationListener`, unconditional and
 *     un-bypassable: a write that moves `status` away from a legacy Dutch
 *     value it is currently holding is rejected -- no declared transition
 *     has an old-dialect `from` entry.
 * The fake also exposes `getCurrentSchemaEntity()`/a fake `SchemaMapper` so
 * `AssetDialectMigrationService::withAssetHardValidationDisabled()`'s
 * fallback (temporarily relaxing gate 1, scoped to a single retry, restored
 * in a `finally`) can be driven end-to-end rather than assumed.
 *
 * A THIRD live-instance defect (orchestrator review, 2026-08-19, "Defect C")
 * surfaced only on a re-run against the real instance, not against the first
 * cut's fake: `unset()`-ing a retired field key does not clear it, because
 * OpenRegister's magic-table UPDATE generates a SET clause only for
 * properties the CURRENT schema still declares -- a property removed from
 * the schema entirely (every retired name in this migration) is invisible
 * to that mechanism whether the key is omitted OR sent as an explicit null.
 * The fix is not "clear the retired key" (impossible) but "stop treating its
 * mere presence as unfinished work once the renamed key holds the same
 * value" -- `testDatetimeAndDateOnlyForTheSameDayAreEquivalentNotConflicting()`
 * and `testRedundantDuplicateFieldIsAlreadyCurrentNotRewritten()` pin that a
 * row in this state is already-current and is NEVER written again.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\AssetDialectMigrationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCP\IAppConfig;

/**
 * Tests for AssetDialectMigrationService.
 *
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */
class AssetDialectMigrationServiceTest extends TestCase {

	/**
	 * Legacy Dutch `Asset.status` values -- the fake's `saveObject()` uses
	 * this to know when to simulate (1) a schema-validation rejection of any
	 * payload still carrying one of these, and (2) a lifecycle-guard
	 * rejection of a write that tries to change AWAY from one. Mirrors the
	 * real Asset schema's closed `status` enum and its
	 * `x-openregister-lifecycle` transitions, none of which declare an
	 * old-dialect `from`.
	 *
	 * @var string[]
	 */
	public const LEGACY_STATUSES = ['beschikbaar', 'uitgegeven', 'ingenomen', 'afgeschreven'];

	/**
	 * The legacy -> English status moves the schema's four migration-only
	 * `migrateLegacyStatus_*` lifecycle transitions declare (the EXPAND half
	 * of the expand/contract enum migration). Mirrors
	 * `hr-assets.json`'s `x-openregister-lifecycle.transitions`; the fake's
	 * gate 2 permits exactly these and refuses every other move out of a
	 * legacy state.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_STATUS_MAP = [
		'beschikbaar'  => 'available',
		'uitgegeven'   => 'issued',
		'ingenomen'    => 'checkedIn',
		'afgeschreven' => 'writtenOff',
	];

	/**
	 * Build a fake ObjectService (+ fake SchemaMapper/Schema entity) double
	 * seeded with rows-by-schema, plus the fully-wired service under test.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: AssetDialectMigrationService, 1: object, 2: object}
	 */
	private function service(array $rowsBySchema): array {
		$schemaEntity = new class {

			/**
			 * @var bool
			 */
			public bool $hardValidation = true;

			/**
			 * @return bool
			 */
			public function getHardValidation(): bool {
				return $this->hardValidation;
			}//end getHardValidation()

			/**
			 * @param bool $hardValidation New value.
			 *
			 * @return void
			 */
			public function setHardValidation(bool $hardValidation): void {
				$this->hardValidation = $hardValidation;
			}//end setHardValidation()

		};

		$schemaMapper = new class {

			/**
			 * @var int
			 */
			public int $updateCalls = 0;

			/**
			 * @param mixed $entity The schema entity (already mutated in place).
			 *
			 * @return mixed
			 */
			public function update(mixed $entity): mixed {
				$this->updateCalls++;
				return $entity;
			}//end update()

		};

		$fake = new class($rowsBySchema, $schemaEntity) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * Every SUCCESSFUL saveObject() call, as `['schema'=>, 'id'=>, 'object'=>]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 * @param object $schemaEntity Fake Schema entity (shared, mutable).
			 */
			public function __construct(
				public array $rowsBySchema,
				private object $schemaEntity,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @return object
			 */
			public function getCurrentSchemaEntity(): object {
				return $this->schemaEntity;
			}//end getCurrentSchemaEntity()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * Simulates, IN THE REAL API's ORDER: (1) full-object schema
			 * validation -- rejects any payload whose `status` is a legacy
			 * Dutch value, unconditional on `$_validation`, gated only by
			 * the shared fake Schema entity's `hardValidation` flag; then
			 * (2) LifecycleValidationListener -- rejects a write that moves
			 * `status` AWAY from a legacy Dutch value it is currently
			 * holding. Every other write succeeds.
			 *
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 * @param bool $_validation Deliberately UNUSED by the fake, matching the real API (measured).
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_validation = true,
			): array {
				$targetSchema = ($schema ?? $this->schema);
				$id = ($uuid ?? ('generated-' . $targetSchema . '-' . $this->nextId++));

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$existing = null;
				$existingIndex = null;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$existing = $row;
						$existingIndex = $i;
						break;
					}
				}

				if ($targetSchema === 'Asset' && array_key_exists('status', $object) === true) {
					$payloadStatus = (string)$object['status'];

					// Gate 1: full-object schema validation (hardValidation-gated).
					if ($this->schemaEntity->getHardValidation() === true
						&& in_array($payloadStatus, AssetDialectMigrationServiceTest::LEGACY_STATUSES, true) === true
					) {
						throw new \RuntimeException(sprintf(
							"Property 'status' should be one of: 'available', 'issued', 'checkedIn', 'writtenOff', but is '%s'. Please choose one of the allowed values.",
							$payloadStatus
						));
					}

					// Gate 2: lifecycle transition guard. Unconditional (it
					// consults no _validation/silent/context flag), but it
					// permits any DECLARED transition — and the Asset schema
					// now declares four migration-only
					// `migrateLegacyStatus_<legacy>` transitions, the EXPAND
					// half of the expand/contract enum migration. So a legacy
					// -> mapped-English move is allowed; anything else from a
					// legacy state is still refused.
					if ($existing !== null) {
						$oldStatus = (string)($existing['status'] ?? '');
						$isLegacy = in_array($oldStatus, AssetDialectMigrationServiceTest::LEGACY_STATUSES, true);
						$declared = (AssetDialectMigrationServiceTest::LEGACY_STATUS_MAP[$oldStatus] ?? null);
						if ($oldStatus !== $payloadStatus && $isLegacy === true && $declared !== $payloadStatus) {
							throw new \RuntimeException(sprintf(
								'No transition allows moving "status" from "%s" to "%s".',
								$oldStatus,
								$payloadStatus
							));
						}
					}
				}//end if

				$saved = array_merge($object, ['id' => $id]);
				$this->saved[] = ['schema' => $targetSchema, 'id' => $id, 'object' => $saved];

				if ($existingIndex !== null) {
					$rows[$existingIndex] = $saved;
				} else {
					$rows[] = $saved;
				}

				$this->rowsBySchema[$targetSchema] = $rows;

				return $saved;
			}//end saveObject()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => ($class === 'OCA\OpenRegister\Db\SchemaMapper') ? $schemaMapper : $fake
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('hrmq');

		$logger = $this->createMock(LoggerInterface::class);

		return [new AssetDialectMigrationService($container, $appConfig, $logger), $fake, $schemaMapper];
	}//end service()

	/**
	 * The measured live shape (tasks.md section 13): a pre-existing bus
	 * Asset with `category: voertuig`, `status: uitgegeven`, the retired
	 * `kenteken`/`aanschafdatum`/`aanschafwaarde` fields, and no fiscal
	 * fields at all.
	 *
	 * @return array<string, mixed>
	 */
	private function oldDialectBus(): array {
		return [
			'id' => 'bus-1',
			'name' => 'Ford Transit bedrijfsbus',
			'category' => 'voertuig',
			'kenteken' => 'V-000-XX',
			'aanschafdatum' => '2024-02-15 00:00:00',
			'aanschafwaarde' => '38500.00',
			'status' => 'uitgegeven',
			'active' => true,
			'administrationId' => 'ADM-001',
		];

	}//end oldDialectBus()

	/**
	 * category/kenteken/aanschafdatum/aanschafwaarde all rewrite -- via the
	 * hard-validation-bypass fallback, since the plain write is rejected by
	 * the fake's simulated schema validation exactly like the real API
	 * (orchestrator review, 2026-08-19: this is the scenario the first cut's
	 * fake never exercised). status is left in place with the lifecycle-
	 * guard rejection recorded as the skip reason on the SAME row (rewritten
	 * and skipped are not mutually exclusive -- class docblock). The fake
	 * Schema entity's `hardValidation` ends up back at its original `true`.
	 *
	 * @return void
	 */
	public function testOldDialectAssetIsRewrittenIncludingStatus(): void {
		[$service, $fake, $schemaMapper] = $this->service(['Asset' => [$this->oldDialectBus()], 'AssetAssignment' => []]);

		$report = $service->migrate();

		self::assertSame(1, $report['Asset']['inspected']);
		self::assertSame(1, $report['Asset']['rewritten']);
		self::assertSame(0, $report['Asset']['alreadyCurrent']);
		self::assertSame(0, $report['Asset']['skipped'], 'expand/contract clears both gates — nothing is left behind');
		self::assertCount(0, $report['Asset']['skipReasons']);

		$saved = $fake->rowsBySchema['Asset'][0];
		self::assertSame('vehicle', $saved['category']);
		self::assertSame('V-000-XX', $saved['licencePlate']);
		// The retired key is left exactly as read, UNCHANGED (Defect C,
		// orchestrator review 2026-08-19): OpenRegister's magic-table UPDATE
		// never generates a SET clause for a property the CURRENT schema no
		// longer declares -- measured on the live instance to be true of
		// BOTH omitting the key AND sending it as an explicit null, so
		// touching it at all is a wasted write. Its stale value is
		// permanent, harmless debris no schema or consumer reads any more.
		self::assertSame('V-000-XX', $saved['kenteken']);
		self::assertSame('2024-02-15', $saved['purchaseDate']);
		self::assertSame('2024-02-15 00:00:00', $saved['aanschafdatum']);
		self::assertSame(38500.0, $saved['purchaseValue']);
		self::assertIsFloat($saved['purchaseValue']);
		self::assertSame('38500.00', $saved['aanschafwaarde']);
		// status IS migrated now: the schema's migrateLegacyStatus_uitgegeven
		// transition declares the move, so the lifecycle guard permits it.
		self::assertSame('issued', $saved['status']);

		// And no schema was mutated to achieve it. This is the regression
		// guard on the removed `hardValidation` toggle: a global, persisted
		// flag that relaxed validation for every concurrent writer of Asset,
		// and that a fatal between toggle and restore would have left off
		// permanently and silently. Expand/contract needs no schema write at
		// all, so any update call here is that mechanism creeping back.
		self::assertSame(0, $schemaMapper->updateCalls, 'the migration must never write to the schema');
		self::assertTrue($fake->getCurrentSchemaEntity()->getHardValidation(), 'hardValidation untouched, never toggled');

	}//end testOldDialectAssetIsRewrittenIncludingStatus()

	/**
	 * Re-running converges: the fields already migrated on the first run
	 * are not re-written a second time (the hard-validation-bypass fallback
	 * does not fire again -- nonStatusChanged is now false), and the still-
	 * blocked status recurs as the identical skip rather than escalating.
	 *
	 * @return void
	 */
	public function testSecondRunIsIdempotent(): void {
		[$service, $fake, $schemaMapper] = $this->service(['Asset' => [$this->oldDialectBus()], 'AssetAssignment' => []]);

		$service->migrate();
		$firstRunSavedCount = count($fake->saved);
		self::assertSame(1, $firstRunSavedCount, 'one write carries fields AND status together');
		self::assertSame(0, $schemaMapper->updateCalls, 'no schema write — expand/contract needs no toggle');

		$secondReport = $service->migrate();

		self::assertSame(1, $secondReport['Asset']['inspected']);
		self::assertSame(0, $secondReport['Asset']['rewritten'], 'nothing left to rewrite — the first run converged fully');
		self::assertSame(0, $secondReport['Asset']['skipped'], 'and nothing left blocked');
		self::assertSame(1, $secondReport['Asset']['alreadyCurrent'], 'the row is now recognised as current');
		self::assertCount($firstRunSavedCount, $fake->saved, 'no additional save happened on the second run');
		self::assertSame(0, $schemaMapper->updateCalls, 'still no schema write on a re-run');

	}//end testSecondRunIsIdempotent()

	/**
	 * A row already fully in the new dialect (the `asset-bus-incomplete-
	 * fiscal` seed shape) is untouched: no saveObject() call at all.
	 *
	 * @return void
	 */
	public function testAlreadyCurrentAssetIsUntouched(): void {
		$row = [
			'id' => 'bus-2',
			'name' => 'Opel Vivaro bestelbus',
			'category' => 'vehicle',
			'status' => 'available',
			'active' => true,
			'administrationId' => 'ADM-001',
			'licencePlate' => 'V-001-XX',
			'purchaseDate' => '2026-03-01',
			'purchaseValue' => 32000,
		];

		[$service, $fake] = $this->service(['Asset' => [$row], 'AssetAssignment' => []]);

		$report = $service->migrate();

		self::assertSame(1, $report['Asset']['inspected']);
		self::assertSame(0, $report['Asset']['rewritten']);
		self::assertSame(1, $report['Asset']['alreadyCurrent']);
		self::assertSame(0, $report['Asset']['skipped']);
		self::assertSame([], $fake->saved, 'an already-current row must never be written');

	}//end testAlreadyCurrentAssetIsUntouched()

	/**
	 * The measured live shape (`06a33edf`, tasks.md section 13 baseline,
	 * orchestrator review 2026-08-19 Defect B): a retired old-dialect field
	 * survives as an explicit NULL-valued key alongside its populated
	 * renamed replacement. A null is an absent value, not a conflicting
	 * one -- the row is already-current, not skipped, and nothing is ever
	 * written.
	 *
	 * @return void
	 */
	public function testAssetWithNullOldKeysAlongsideNewValuesIsAlreadyCurrent(): void {
		$row = [
			'id' => 'bus-3',
			'name' => 'Opel Vivaro bestelbus',
			'category' => 'vehicle',
			'status' => 'available',
			'kenteken' => null,
			'aanschafdatum' => null,
			'aanschafwaarde' => null,
			'licencePlate' => 'V-001-XX',
			'purchaseDate' => '2026-03-01',
			'purchaseValue' => 32000,
		];

		[$service, $fake] = $this->service(['Asset' => [$row], 'AssetAssignment' => []]);

		$report = $service->migrate();

		self::assertSame(1, $report['Asset']['inspected']);
		self::assertSame(0, $report['Asset']['rewritten']);
		self::assertSame(1, $report['Asset']['alreadyCurrent']);
		self::assertSame(0, $report['Asset']['skipped'], 'a null old-name key must never read as a conflict');
		self::assertSame([], $fake->saved, 'an already-current row must never be written');

	}//end testAssetWithNullOldKeysAlongsideNewValuesIsAlreadyCurrent()

	/**
	 * The measured live shape (`ec3c7dbc`/`663f44d8`'s SECOND-run report,
	 * orchestrator review 2026-08-19 Defect C): `aanschafdatum` carries the
	 * OLD datetime-with-time-of-day precision ("2024-02-15 00:00:00") while
	 * `purchaseDate` (`format: date`) carries the SAME calendar day at date-
	 * only precision ("2024-02-15") -- not a conflict, a precision
	 * difference. Both names already hold the (same) value, so there is
	 * nothing left to write -- already-current, never skipped, never
	 * written (a retired key can never actually be cleared through
	 * saveObject() once the schema stops declaring it -- see
	 * `applyFieldRenames()` -- so re-attempting a write here every run would
	 * itself have been the idempotency bug).
	 *
	 * @return void
	 */
	public function testDatetimeAndDateOnlyForTheSameDayAreEquivalentNotConflicting(): void {
		$row = [
			'id' => 'bus-4',
			'name' => 'Ford Transit bedrijfsbus',
			'category' => 'vehicle',
			'status' => 'available',
			'aanschafdatum' => '2024-02-15 00:00:00',
			'purchaseDate' => '2024-02-15',
		];

		[$service, $fake] = $this->service(['Asset' => [$row], 'AssetAssignment' => []]);

		$report = $service->migrate();

		self::assertSame(1, $report['Asset']['alreadyCurrent']);
		self::assertSame(0, $report['Asset']['rewritten']);
		self::assertSame(0, $report['Asset']['skipped'], 'a same-day datetime/date-only pair must never read as a conflict');
		self::assertSame([], $fake->saved, 'nothing left to write -- the retired key can never be cleared anyway');

	}//end testDatetimeAndDateOnlyForTheSameDayAreEquivalentNotConflicting()

	/**
	 * An unrecognised `category` (neither the old-dialect map nor the
	 * current enum) is skipped-with-reason, never guessed, and never
	 * written at all.
	 *
	 * @return void
	 */
	public function testUnrecognisedCategoryIsSkippedNotWritten(): void {
		$row = [
			'id' => 'asset-x',
			'name' => 'Unknown thing',
			'category' => 'onbekend',
			'status' => 'available',
		];

		[$service, $fake] = $this->service(['Asset' => [$row], 'AssetAssignment' => []]);

		$report = $service->migrate();

		self::assertSame(1, $report['Asset']['skipped']);
		self::assertSame(0, $report['Asset']['rewritten']);
		self::assertStringContainsString('onbekend', $report['Asset']['skipReasons'][0]['reason']);
		self::assertSame([], $fake->saved);

	}//end testUnrecognisedCategoryIsSkippedNotWritten()

	/**
	 * The measured live conflict (tasks.md section 13 baseline): an
	 * AssetAssignment carrying BOTH the retired `uitgifteBonSigned` and its
	 * renamed replacement `issueReceiptSigned` with DIFFERENT NON-NULL
	 * values is left untouched -- the migration refuses to guess which is
	 * authoritative.
	 *
	 * @return void
	 */
	public function testConflictingRenamedFieldValuesAreSkippedNotGuessed(): void {
		$row = [
			'id' => 'assignment-1',
			'assetId' => 'bus-1',
			'employeeId' => 'emp-1',
			'issuedOn' => '2025-01-06',
			'returnedOn' => '2025-12-19',
			'uitgifteBonSigned' => false,
			'issueReceiptSigned' => true,
		];

		[$service, $fake] = $this->service(['Asset' => [], 'AssetAssignment' => [$row]]);

		$report = $service->migrate();

		self::assertSame(1, $report['AssetAssignment']['skipped']);
		self::assertSame(0, $report['AssetAssignment']['rewritten']);
		$reason = $report['AssetAssignment']['skipReasons'][0]['reason'];
		self::assertStringContainsString('uitgifteBonSigned', $reason);
		self::assertStringContainsString('issueReceiptSigned', $reason);
		self::assertSame([], $fake->saved);

	}//end testConflictingRenamedFieldValuesAreSkippedNotGuessed()

	/**
	 * The OTHER measured live row: both the retired and renamed fields
	 * present with the SAME value is not a guess, and it is also not
	 * further work -- `issueReceiptSigned` already carries the right value,
	 * and `uitgifteBonSigned` can never actually be cleared through
	 * saveObject() once the schema stops declaring it (Defect C, class
	 * docblock), so the row is already-current.
	 *
	 * @return void
	 */
	public function testRedundantDuplicateFieldIsAlreadyCurrentNotRewritten(): void {
		$row = [
			'id' => 'assignment-2',
			'assetId' => 'phone-1',
			'employeeId' => 'emp-2',
			'issuedOn' => '2026-06-15',
			'uitgifteBonSigned' => false,
			'issueReceiptSigned' => false,
		];

		[$service, $fake] = $this->service(['Asset' => [], 'AssetAssignment' => [$row]]);

		$report = $service->migrate();

		self::assertSame(1, $report['AssetAssignment']['alreadyCurrent']);
		self::assertSame(0, $report['AssetAssignment']['rewritten']);
		self::assertSame(0, $report['AssetAssignment']['skipped']);
		self::assertSame([], $fake->saved, 'nothing left to write -- the retired key can never be cleared anyway');

	}//end testRedundantDuplicateFieldIsAlreadyCurrentNotRewritten()

	/**
	 * Plain AssetAssignment field renames (`uitgifteDatum`/`innameDatum`/
	 * `eigenBijdrage`) with no new-name counterpart present.
	 *
	 * @return void
	 */
	public function testAssignmentFieldRenamesApply(): void {
		$row = [
			'id' => 'assignment-3',
			'assetId' => 'bus-1',
			'employeeId' => 'emp-3',
			'uitgifteDatum' => '2025-01-06',
			'innameDatum' => '2025-12-19',
			'eigenBijdrage' => 325.0,
		];

		[$service, $fake] = $this->service(['Asset' => [], 'AssetAssignment' => [$row]]);

		$report = $service->migrate();

		self::assertSame(1, $report['AssetAssignment']['rewritten']);
		self::assertSame(0, $report['AssetAssignment']['skipped']);

		$saved = $fake->rowsBySchema['AssetAssignment'][0];
		self::assertSame('2025-01-06', $saved['issuedOn']);
		self::assertSame('2025-12-19', $saved['returnedOn']);
		self::assertSame(325.0, $saved['employeeContribution']);
		// The retired keys are left exactly as read, UNCHANGED (Defect C,
		// orchestrator review 2026-08-19): a retired key can never actually
		// be cleared through saveObject() once the schema stops declaring
		// it (measured: neither omitting it nor sending an explicit null
		// produces a SET clause for it), so this migration does not attempt
		// to touch it at all -- see `applyFieldRenames()`'s docblock.
		self::assertSame('2025-01-06', $saved['uitgifteDatum']);
		self::assertSame('2025-12-19', $saved['innameDatum']);
		self::assertSame(325.0, $saved['eigenBijdrage']);

	}//end testAssignmentFieldRenamesApply()

	/**
	 * An already-current AssetAssignment (no retired field present at all)
	 * is untouched.
	 *
	 * @return void
	 */
	public function testAlreadyCurrentAssignmentIsUntouched(): void {
		$row = [
			'id' => 'assignment-4',
			'assetId' => 'bus-1',
			'employeeId' => 'emp-4',
			'issuedOn' => '2026-01-01',
			'returnedOn' => null,
			'issueReceiptSigned' => true,
			'employeeContribution' => 0,
		];

		[$service, $fake] = $this->service(['Asset' => [], 'AssetAssignment' => [$row]]);

		$report = $service->migrate();

		self::assertSame(1, $report['AssetAssignment']['alreadyCurrent']);
		self::assertSame(0, $report['AssetAssignment']['rewritten']);
		self::assertSame(0, $report['AssetAssignment']['skipped']);
		self::assertSame([], $fake->saved);

	}//end testAlreadyCurrentAssignmentIsUntouched()

	/**
	 * The measured live shape (`292c7aae`/`ee0bd31b`, orchestrator review
	 * 2026-08-19 Defect B): `uitgifteDatum` survives as an explicit
	 * NULL-valued key alongside a populated `issuedOn`. Already-current, not
	 * skipped.
	 *
	 * @return void
	 */
	public function testAssignmentWithNullOldKeyAlongsideNewValueIsAlreadyCurrent(): void {
		$row = [
			'id' => 'assignment-5',
			'assetId' => 'phone-1',
			'employeeId' => 'emp-5',
			'uitgifteDatum' => null,
			'issuedOn' => '2026-06-15',
			'issueReceiptSigned' => false,
		];

		[$service, $fake] = $this->service(['Asset' => [], 'AssetAssignment' => [$row]]);

		$report = $service->migrate();

		self::assertSame(1, $report['AssetAssignment']['alreadyCurrent']);
		self::assertSame(0, $report['AssetAssignment']['rewritten']);
		self::assertSame(0, $report['AssetAssignment']['skipped'], 'a null old-name key must never read as a conflict');
		self::assertSame([], $fake->saved);

	}//end testAssignmentWithNullOldKeyAlongsideNewValueIsAlreadyCurrent()

	/**
	 * `migrate()` always reports both schema keys with the full count shape,
	 * even when a schema has zero rows.
	 *
	 * @return void
	 */
	public function testReportShapeCoversBothSchemasEvenWhenEmpty(): void {
		[$service] = $this->service(['Asset' => [], 'AssetAssignment' => []]);

		$report = $service->migrate();

		self::assertArrayHasKey('Asset', $report);
		self::assertArrayHasKey('AssetAssignment', $report);
		foreach (['Asset', 'AssetAssignment'] as $schema) {
			self::assertSame(0, $report[$schema]['inspected']);
			self::assertSame(0, $report[$schema]['rewritten']);
			self::assertSame(0, $report[$schema]['alreadyCurrent']);
			self::assertSame(0, $report[$schema]['skipped']);
			self::assertSame([], $report[$schema]['skipReasons']);
		}

	}//end testReportShapeCoversBothSchemasEvenWhenEmpty()

}//end class
