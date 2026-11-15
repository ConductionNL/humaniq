<?php

/**
 * Rule Test Data Run
 *
 * One run of the rule test-data seeder: where it writes, as whom, how it writes
 * one object type's samples or field defaults, and how many object types and
 * rows it found already compliant. Every write bypasses RBAC and multitenancy
 * and acts as the resolved admin user, the way the seeder always has. A type or
 * row that cannot be read or written is logged and skipped.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * The register, the acting user and the running counts of one seeder run.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
 */
final class RuleTestDataRun {

	/**
	 * Rows and object types found already compliant so far.
	 *
	 * @var integer
	 */
	public int $alreadyCompliant = 0;

	/**
	 * @param mixed $objectService The OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param IUser|null $admin Admin user for the writes.
	 * @param LoggerInterface $logger Logs the rows and types that could not be read or written.
	 */
	public function __construct(
		public readonly mixed $objectService,
		public readonly string $register,
		private readonly ?IUser $admin,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The rows of one object type.
	 *
	 * @param string $objectType Schema name.
	 * @param int $limit The most rows to read.
	 *
	 * @return mixed The rows, as the ObjectService returns them.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function rows(string $objectType, int $limit): mixed {
		return $this->objectService->setRegister($this->register)->setSchema($objectType)->findAll(['limit' => $limit]);

	}//end rows()

	/**
	 * Update one object in place when it has an id, create it otherwise.
	 *
	 * @param string $objectType Schema name.
	 * @param array<string, mixed> $object The object.
	 * @param string $uuid The id of the existing object, or '' to create.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function write(string $objectType, array $object, string $uuid): void {
		if ($uuid !== '') {
			$this->objectService->saveObject(object: $object, register: $this->register, schema: $objectType, uuid: $uuid, _rbac: false, _multitenancy: false, currentUser: $this->admin);
			return;
		}

		$this->objectService->saveObject(object: $object, register: $this->register, schema: $objectType, _rbac: false, _multitenancy: false, currentUser: $this->admin);

	}//end write()

	/**
	 * Fill the provider's field defaults into every existing row of one object
	 * type that lacks them or holds them blank.
	 *
	 * @param string $objectType Schema name.
	 * @param array<string, mixed> $fields The field defaults, keyed by field name.
	 *
	 * @return int The number of rows updated.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function backfillFields(string $objectType, array $fields): int {
		try {
			$rows = $this->rows(objectType: $objectType, limit: 10000);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot load ' . $objectType . ' for provider seeding: ' . $e->getMessage());
			return 0;
		}

		$added = 0;
		foreach ($rows as $row) {
			$obj = is_array($row) === true ? $row : $row->jsonSerialize();
			$rowId = (string)($obj['id'] ?? $obj['@self']['id'] ?? '');
			$filled = self::withDefaults(object: $obj, defaults: $fields);
			if ($filled === null) {
				$this->alreadyCompliant++;
				continue;
			}

			unset($filled['@self']);
			try {
				$this->objectService->saveObject(object: $filled, register: $this->register, schema: $objectType, uuid: $rowId, _rbac: false, _multitenancy: false, currentUser: $this->admin);
				$added++;
			} catch (\Throwable $e) {
				$this->logger->warning('RuleTestDataSeeder: provider field backfill failed for ' . $objectType . ' ' . $rowId . ': ' . $e->getMessage());
			}
		}//end foreach

		return $added;

	}//end backfillFields()

	/**
	 * One row with every missing or blank default field filled in.
	 *
	 * @param array<string, mixed> $object The row.
	 * @param array<string, mixed> $defaults The field defaults, keyed by field name.
	 *
	 * @return array<string, mixed>|null The filled row, or null when it already had every field.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	private static function withDefaults(array $object, array $defaults): ?array {
		$changed = false;
		foreach ($defaults as $field => $default) {
			if (array_key_exists($field, $object) === false || trim((string)($object[$field] ?? '')) === '') {
				$object[$field] = $default;
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $object;

	}//end withDefaults()

	/**
	 * Upsert provider samples on a natural key (cao-library, REQ-CAO-006):
	 * for each sample, find the existing row whose key field equals the
	 * sample's key value and update it in place (preserving its object id);
	 * create a new object when none matches. Idempotent: re-running creates no
	 * duplicate and converges each row's fields to the sample. Returns the
	 * number of objects written (created or updated).
	 *
	 * @param string $objectType Schema name.
	 * @param array<int, array<string, mixed>> $samples The provider's samples.
	 * @param string $keyField The natural-key field.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function upsertSamples(string $objectType, array $samples, string $keyField): int {
		$idByKey = $this->idsByKey(objectType: $objectType, keyField: $keyField);
		if ($idByKey === null) {
			return 0;
		}

		$written = 0;
		foreach ($samples as $sample) {
			$keyValue = trim((string)($sample[$keyField] ?? ''));
			if ($keyValue === '') {
				continue;
			}

			try {
				$this->write(objectType: $objectType, object: $sample, uuid: ($idByKey[$keyValue] ?? ''));
				$written++;
			} catch (\Throwable $e) {
				$this->logger->warning('RuleTestDataSeeder: upsert ' . $objectType . ' ' . $keyValue . ' failed: ' . $e->getMessage());
			}
		}

		return $written;

	}//end upsertSamples()

	/**
	 * The ids of the existing rows of one object type, by natural-key value.
	 *
	 * @param string $objectType Schema name.
	 * @param string $keyField The natural-key field.
	 *
	 * @return array<string, string>|null Ids by key value, or null when the rows cannot be read.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	private function idsByKey(string $objectType, string $keyField): ?array {
		try {
			$rows = $this->rows(objectType: $objectType, limit: 10000);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot load ' . $objectType . ' for upsert seeding: ' . $e->getMessage());
			return null;
		}

		$idByKey = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$obj = is_array($row) === true ? $row : $row->jsonSerialize();
			$keyValue = trim((string)($obj[$keyField] ?? ''));
			if ($keyValue === '') {
				continue;
			}

			$idByKey[$keyValue] = (string)($obj['id'] ?? $obj['@self']['id'] ?? '');
		}

		return $idByKey;

	}//end idsByKey()

	/**
	 * Create the provider's sample objects for one object type when the type
	 * currently has no rows (create-once-when-empty, the default SeedsObjects
	 * behaviour for providers not also implementing UpsertsObjects). A type
	 * that already has rows counts as already compliant.
	 *
	 * @param string $objectType Schema name.
	 * @param array<int, array<string, mixed>> $samples The provider's samples.
	 *
	 * @return int The number of objects created.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function createMissingSamples(string $objectType, array $samples): int {
		try {
			$existing = $this->rows(objectType: $objectType, limit: 1);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot probe ' . $objectType . ' for object seeding: ' . $e->getMessage());
			return 0;
		}

		if (is_array($existing) === true && $existing !== []) {
			$this->alreadyCompliant++;
			return 0;
		}

		$created = 0;
		foreach ($samples as $sample) {
			try {
				$this->objectService->saveObject(object: $sample, register: $this->register, schema: $objectType, _rbac: false, _multitenancy: false, currentUser: $this->admin);
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning('RuleTestDataSeeder: sample ' . $objectType . ' create failed: ' . $e->getMessage());
			}
		}

		return $created;

	}//end createMissingSamples()
}//end class
