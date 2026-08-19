<?php

/**
 * Payroll Retention Guard Service
 *
 * hrmq#99 (consume-not-rebuild correction): the SINGLE place that turns an
 * already-known retention ceiling into a real OpenRegister legal hold, so
 * OpenRegister's own guarded erase (`Gdpr\DataSubjectRequestService::erase()`,
 * `RetentionService::hasActiveLegalHold()`/`validateNotImmutable()`) refuses
 * a still-retained payroll object on its own -- never a bespoke hrmq
 * per-object exclusion computed ad hoc at DSAR time (the deleted
 * `AvgDsrRetentionClassifier`'s job).
 *
 * `RetentionService`'s guarded erase does NOT itself gate on a populated
 * `retention.archiefactiedatum` -- only on an active legal hold or an
 * immutable archival status (openregister#475; confirmed by reading
 * `DataSubjectRequestService::retentionGuard()`, which calls only
 * `hasActiveLegalHold()` and `validateNotImmutable()`). A populated ceiling
 * date alone is therefore NOT protective for erasure. This service reads
 * whichever authoritative ceiling date is already available for a payroll
 * object --
 *
 *   - a populated `retainedUntil` field (Payslip/LoonaangifteFiling carry
 *     one already, populated by unrelated FR/US/DE compliance-evidence
 *     checks -- `EuUsPayrollChecks`/`NlWageTaxFilingChecks`, unchanged by
 *     hrmq#99), or
 *   - OpenRegister's own `retention.archiefactiedatum`, when the object's
 *     schema carries an `archive` config (`RetentionService
 *     ::applyArchivalMetadata()` computes it automatically on save; hrmq
 *     does no date derivation of its own for this case) --
 *
 * and, when that date has not yet passed, PLACES a legal hold
 * (`RetentionService::placeLegalHold()`) so the guarded erase actually
 * refuses the object. `syncLegalHold()` itself computes no retention
 * duration -- it only reads an existing date and syncs OpenRegister's
 * enforcement primitive to it. A hold, once placed, is never auto-released
 * here -- releasing it is a deliberate HR/finance action once the retention
 * window has genuinely lapsed (see `NlDossierRetentionChecks
 * ::nl-bewaartermijn-verstreken`, which flags that moment without acting on
 * it).
 *
 * REGRESSION FOUND AND FIXED post-review (hrmq#99, second pass): a plain NL
 * `Payslip` carries NEITHER a populated `retainedUntil` (that field is
 * populated only for LoonaangifteFiling/FR/US/DE payslips by unrelated
 * checks) NOR an OpenRegister-computed `archiefactiedatum` (the `Payslip`
 * schema has no `archive` config) -- `syncLegalHold()` alone is therefore a
 * no-op for the PRIMARY case, and a normal sealed NL payslip was left fully
 * erasable, a net regression versus the deleted classifier (which at least
 * DERIVED the AWR floor for this exact case). Live-verified against
 * OpenRegister HEAD: `RetentionService::calculateArchiefactiedatum()`
 * computes `brondatum + bewaartermijn` (a fixed `DateInterval` from a source
 * date) -- it has no "round up to 31 December of a target year" mode, so a
 * schema-config-only path cannot reproduce "31 December of (period year + 7)"
 * exactly for a `YYYY-MM` period field without either widening the interval
 * (a padding hack) or adding a new derived date field to the schema (the
 * "bespoke field" shape this fix is supposed to remove). `placeStatutoryFloorHold()`
 * is therefore the ONE place the AWR "period year + N, 31 December" formula
 * is computed for the WRITE/hold-placing side of this concern (replacing the
 * deleted classifier's copy) -- called at Payslip creation
 * (`PayrollRunService::savePayslip()`). `NlWageTaxFilingChecks
 * ::retainedYearsAfterPeriod()` (private, a different `CheckProvider`)
 * remains a SEPARATE, pre-existing, unrelated derivation for the AUDIT/CHECK
 * side (verifying a manually-entered `retainedUntil` on `LoonaangifteFiling`
 * meets the floor) -- not touched by hrmq#99, not a second hold-placing copy.
 * `PensionFiling`/`LoonaangifteFiling` have NO discovered hrmq-owned creation
 * service in this codebase to hook a hold into (`grep`-confirmed: no service
 * class saves either schema; both currently have zero live objects) --
 * `placeStatutoryFloorHold()` is written generically (period field + years +
 * law reference as parameters) so wiring them in is a one-line call once such
 * a service exists, not a new formula.
 *
 * Persistence gotcha (live-verified against openregister HEAD, hrmq#99):
 * `ObjectService::saveObject(object: $entity, ...)` silently DISCARDS
 * entity-level mutations like `retention`/`legalHold` -- internally it calls
 * `extractUuidAndNormalizeObject()`, which flattens the passed `ObjectEntity`
 * down to `$entity->getObject()` (the schema-payload array only) BEFORE
 * persisting, exactly the same PUT-semantic "entity round-trips through an
 * array" shape that drops any field the schema payload does not carry.
 * `RetentionController::placeLegalHold()` (OpenRegister's own reference
 * caller) instead persists via `MagicMapper::update($object)` directly on the
 * mutated entity -- no array round-trip, every entity-level column
 * (`retention` included) survives. This service therefore resolves and calls
 * `OCA\OpenRegister\Db\MagicMapper::update()`, never `ObjectService
 * ::saveObject()`, to persist a placed/inherited hold.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads an object's already-known retention ceiling and syncs it onto
 * OpenRegister's own legal-hold primitive; where no such ceiling is
 * reachable (a plain NL Payslip -- see the class docblock's REGRESSION
 * note), derives the statutory floor directly, in exactly one place.
 */
class PayrollRetentionGuardService {

	/**
	 * AWR art. 52 lid 4 fiscal administratie bewaarplicht -- the statutory
	 * retention duration in years for the NL payroll/loonadministratie
	 * family. Exposed as a public constant so every caller (currently
	 * `PayrollRunService`) cites the SAME value rather than a repeated
	 * literal `7`.
	 *
	 * @var int
	 */
	public const AWR_RETENTION_YEARS = 7;

	/**
	 * The law reference recorded on an AWR-derived hold's reason.
	 *
	 * @var string
	 */
	public const AWR_LAW_REFERENCE = 'AWR art. 52 lid 4';

	/**
	 * The FQCN of OpenRegister's retention/legal-hold service, resolved via
	 * the DI container only (ADR-022).
	 *
	 * @var string
	 */
	private const RETENTION_SERVICE_FQCN = 'OCA\OpenRegister\Service\RetentionService';

	/**
	 * The legal-hold reason recorded when this service places one.
	 *
	 * @var string
	 */
	private const HOLD_REASON_PREFIX = 'Statutaire bewaarplicht (hrmq#99) tot ';

	/**
	 * The FQCN of OpenRegister's object mapper, resolved via the DI container
	 * only (ADR-022). `update()` persists an ObjectEntity's entity-level
	 * columns (including `retention`) directly -- unlike `ObjectService
	 * ::saveObject()`, it never round-trips the entity through its
	 * schema-payload array first (see the class docblock's Persistence
	 * gotcha).
	 *
	 * @var string
	 */
	private const OBJECT_MAPPER_FQCN = 'OCA\OpenRegister\Db\MagicMapper';

	/**
	 * @param ContainerInterface $container DI container for the lazy RetentionService/MagicMapper resolve.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Sync a legal hold onto `$object` when it carries an already-known,
	 * still-open retention ceiling -- a populated `retainedUntil` field, or
	 * OpenRegister's own computed `retention.archiefactiedatum`. A no-op
	 * when no ceiling is known, the ceiling has already passed, or a legal
	 * hold is already active (idempotent -- safe to call on every save).
	 *
	 * @param mixed $object The OpenRegister ObjectEntity to sync.
	 * @param string $schema The schema name (for the warning log message only).
	 *
	 * @return array{held: bool, ceiling: string|null, source: string|null} Whether a hold is (now) active, its ceiling date, and which field it came from.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 */
	public function syncLegalHold(mixed $object, string $schema): array {
		// hrmq#99 live-verify finding: `method_exists($object, 'getRetention')`
		// is ALWAYS false for a real OpenRegister ObjectEntity -- its
		// getters/setters are magic (`__call`, generated from `addType()`
		// declarations on the Nextcloud `Entity` base class), never literal
		// declared methods, so `method_exists()` cannot see them even though
		// calling them works fine. A `method_exists` gate here silently
		// short-circuited every real call. `is_object()` plus a try/catch
		// around the actual magic-method calls is the correct guard.
		if (is_object($object) === false) {
			return ['held' => false, 'ceiling' => null, 'source' => null];
		}

		[$ceiling, $source] = $this->resolveCeiling($object);

		if ($ceiling === null) {
			return ['held' => false, 'ceiling' => null, 'source' => null];
		}

		$today = (new DateTimeImmutable('today'));
		if ($ceiling < $today) {
			// Ceiling already passed -- nothing to newly protect here;
			// NlDossierRetentionChecks::nl-bewaartermijn-verstreken flags
			// this moment separately (never acted on automatically).
			return ['held' => $this->retentionService()->hasActiveLegalHold(object: $object), 'ceiling' => $ceiling->format('Y-m-d'), 'source' => $source];
		}

		if ($this->retentionService()->hasActiveLegalHold(object: $object) === true) {
			return ['held' => true, 'ceiling' => $ceiling->format('Y-m-d'), 'source' => $source];
		}

		$reason = self::HOLD_REASON_PREFIX . $ceiling->format('Y-m-d') . ' (' . $source . ')';
		$held = $this->retentionService()->placeLegalHold(object: $object, reason: $reason);

		if ($this->persist(held: $held, schema: $schema) === false) {
			return ['held' => false, 'ceiling' => $ceiling->format('Y-m-d'), 'source' => $source];
		}

		return ['held' => true, 'ceiling' => $ceiling->format('Y-m-d'), 'source' => $source];
	}//end syncLegalHold()

	/**
	 * Place a legal hold deriving the statutory floor directly from a
	 * period-shaped field (`YYYY-MM`/`YYYY-Pnn`/`YYYY-MM-DD`) -- "31 December
	 * of (period year + `$years`)" -- when no OpenRegister-native ceiling
	 * (`retainedUntil`/`archiefactiedatum`) is available to sync instead
	 * (`syncLegalHold()`). THE single place this formula is computed for the
	 * write/hold-placing side of this concern (see the class docblock's
	 * REGRESSION note) -- never duplicate this derivation elsewhere; extend
	 * this method's callers instead. Idempotent (never re-places an
	 * already-active hold) and safe to call on every save.
	 *
	 * @param mixed $object The OpenRegister ObjectEntity to place the hold on.
	 * @param string $schema The schema name (for the warning log message only).
	 * @param string $periodField The period-shaped field to read the year from (e.g. `period`).
	 * @param int $years The statutory retention duration in years (e.g. 7 for AWR art. 52 lid 4).
	 * @param string $lawReference A short citation recorded on the hold reason (e.g. "AWR art. 52 lid 4").
	 *
	 * @return array{held: bool, ceiling: string|null} Whether a hold is (now) active and its derived ceiling date.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 */
	public function placeStatutoryFloorHold(mixed $object, string $schema, string $periodField, int $years, string $lawReference): array {
		if (is_object($object) === false) {
			return ['held' => false, 'ceiling' => null];
		}

		if ($this->retentionService()->hasActiveLegalHold(object: $object) === true) {
			return ['held' => true, 'ceiling' => null];
		}

		[$periodYear, $periodValue] = $this->extractPeriodYear($object, $periodField);
		if ($periodYear === null) {
			return ['held' => false, 'ceiling' => null];
		}

		$ceiling = sprintf('%04d-12-31', ($periodYear + $years));
		$reason = sprintf(
			'Statutaire bewaarplicht (hrmq#99): %s, tot %s (periode %s + %d jaar).',
			$lawReference,
			$ceiling,
			$periodValue,
			$years
		);

		$held = $this->retentionService()->placeLegalHold(object: $object, reason: $reason);

		if ($this->persist(held: $held, schema: $schema) === false) {
			return ['held' => false, 'ceiling' => $ceiling];
		}

		return ['held' => true, 'ceiling' => $ceiling];
	}//end placeStatutoryFloorHold()

	/**
	 * Extract a 4-digit year from a period-shaped field
	 * (`YYYY-MM`/`YYYY-Pnn`/`YYYY-MM-DD`) on `$object`'s payload, alongside
	 * the field's raw value (for the hold reason message).
	 *
	 * @param mixed $object The OpenRegister ObjectEntity.
	 * @param string $periodField The field name to read.
	 *
	 * @return array{0: int|null, 1: string}
	 */
	private function extractPeriodYear(mixed $object, string $periodField): array {
		try {
			$payload = ($object->getObject() ?? []);
		} catch (\Throwable $e) {
			return [null, ''];
		}

		$value = (string)($payload[$periodField] ?? '');
		if (preg_match('/^(\d{4})/', $value, $matches) === 1) {
			return [(int)$matches[1], $value];
		}

		return [null, $value];
	}//end extractPeriodYear()

	/**
	 * Whether `$object` is currently under active retention -- an active
	 * legal hold, an immutable archival status, or (defensively) a still-open
	 * ceiling that has not yet been synced onto a hold. Used to decide
	 * whether a DERIVED object (a generated PDF) must inherit the same
	 * protection (hrmq#99 hole #1).
	 *
	 * @param mixed $object The OpenRegister ObjectEntity to check.
	 *
	 * @return bool
	 */
	public function isUnderActiveRetention(mixed $object): bool {
		// See syncLegalHold()'s docblock note: `method_exists` cannot see a
		// real ObjectEntity's magic getters -- `is_object()` is the correct
		// guard here.
		if (is_object($object) === false) {
			return false;
		}

		if ($this->retentionService()->hasActiveLegalHold(object: $object) === true) {
			return true;
		}

		if ($this->retentionService()->validateNotImmutable(object: $object) !== null) {
			return true;
		}

		[$ceiling] = $this->resolveCeiling($object);
		if ($ceiling === null) {
			return false;
		}

		return $ceiling >= (new DateTimeImmutable('today'));
	}//end isUnderActiveRetention()

	/**
	 * Place a legal hold on a DERIVED object (e.g. a generated loonstrook/
	 * jaaropgaaf PDF's `GeneratedDocument`) inheriting its source's retention
	 * (hrmq#99 hole #1) -- never a fresh `retainedUntil` field on the derived
	 * object.
	 *
	 * @param mixed $object The derived OpenRegister ObjectEntity.
	 * @param string $schema The schema name (for the warning log message only).
	 * @param string $sourceDescription A short, non-PII description of the source (e.g. "Payslip <uuid>") for the hold reason.
	 *
	 * @return bool Whether the hold is (now) active on the derived object.
	 */
	public function inheritLegalHold(mixed $object, string $schema, string $sourceDescription): bool {
		// See syncLegalHold()'s docblock note: `method_exists` cannot see a
		// real ObjectEntity's magic getters -- `is_object()` is the correct
		// guard here.
		if (is_object($object) === false) {
			return false;
		}

		if ($this->retentionService()->hasActiveLegalHold(object: $object) === true) {
			return true;
		}

		$reason = 'Geërfd van retentie-/legal-hold-status van ' . $sourceDescription . ' (hrmq#99).';
		$held = $this->retentionService()->placeLegalHold(object: $object, reason: $reason);

		return $this->persist(held: $held, schema: $schema);
	}//end inheritLegalHold()

	/**
	 * Resolve the authoritative, already-known retention ceiling for an
	 * object -- a populated `retainedUntil` field wins (mirrors the deleted
	 * classifier's own precedence for the objects that already carry one),
	 * else OpenRegister's own computed `retention.archiefactiedatum`. Reads
	 * only; derives nothing.
	 *
	 * @param mixed $object The OpenRegister ObjectEntity.
	 *
	 * @return array{0: DateTimeImmutable|null, 1: string|null}
	 */
	private function resolveCeiling(mixed $object): array {
		// See syncLegalHold()'s docblock note: a real ObjectEntity's
		// getObject()/getRetention() are magic methods, invisible to
		// `method_exists()` -- a try/catch around the actual call is the
		// correct defensive guard, not a `method_exists` pre-check.
		try {
			$payload = ($object->getObject() ?? []);
		} catch (\Throwable $e) {
			$payload = [];
		}

		$retainedUntil = trim((string)($payload['retainedUntil'] ?? ''));
		if ($retainedUntil !== '') {
			$timestamp = strtotime($retainedUntil);
			if ($timestamp !== false) {
				return [(new DateTimeImmutable())->setTimestamp($timestamp), 'retainedUntil'];
			}
		}

		try {
			$retention = ($object->getRetention() ?? []);
		} catch (\Throwable $e) {
			$retention = [];
		}

		$archiefactiedatum = trim((string)($retention['archiefactiedatum'] ?? ''));
		if ($archiefactiedatum !== '') {
			$timestamp = strtotime($archiefactiedatum);
			if ($timestamp !== false) {
				return [(new DateTimeImmutable())->setTimestamp($timestamp), 'retention.archiefactiedatum'];
			}
		}

		return [null, null];
	}//end resolveCeiling()

	/**
	 * Persist a mutated (held) entity via `MagicMapper::update()` -- shared
	 * by every method that places a hold, logging and reporting failure
	 * uniformly instead of duplicating the try/catch three times.
	 *
	 * @param mixed $held The entity, already mutated by `RetentionService::placeLegalHold()`.
	 * @param string $schema The schema name (for the warning log message only).
	 *
	 * @return bool Whether the persist succeeded.
	 */
	private function persist(mixed $held, string $schema): bool {
		try {
			$this->objectMapper()->update($held);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PayrollRetentionGuardService: kon legal hold niet opslaan voor ' . $schema . ': ' . $e->getMessage()
			);
			return false;
		}

		return true;
	}//end persist()

	/**
	 * @return mixed OpenRegister's RetentionService.
	 */
	private function retentionService(): mixed {
		return $this->container->get(self::RETENTION_SERVICE_FQCN);
	}//end retentionService()

	/**
	 * @return mixed OpenRegister's MagicMapper -- `update()` persists an
	 *               entity's entity-level columns (including `retention`)
	 *               directly (see the class docblock's Persistence gotcha).
	 */
	private function objectMapper(): mixed {
		return $this->container->get(self::OBJECT_MAPPER_FQCN);
	}//end objectMapper()

}//end class
