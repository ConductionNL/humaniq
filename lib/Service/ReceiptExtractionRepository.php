<?php

/**
 * Receipt Extraction Repository
 *
 * The OpenRegister persistence layer for receipt-ocr (design.md D1/D6):
 * loading/finding `Expense` and `ReceiptExtraction` rows and the plain
 * field-merge saves for both schemas. Split out of `ReceiptExtractionService`
 * purely to keep that service's own PHPMD `ExcessiveClassComplexity` within
 * the mechanical quality gate -- this class is I/O plumbing only; every
 * DECISION (the prefill mapping, the idempotency pre-check's OUTCOME,
 * docudesk duck-typing) stays in `ReceiptExtractionService`. Mirrors the
 * plain `objectService()->saveObject(...)` idiom `HrDocumentService` uses for
 * its own `GeneratedDocument`/`Jaaropgaaf` rows.
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Loads/finds/saves the Expense and ReceiptExtraction rows this leaf touches.
 */
class ReceiptExtractionRepository {

	/**
	 * OpenRegister's ObjectService, resolved by string FQCN.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE_FQCN = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * @var string
	 */
	private const EXPENSE_SCHEMA = 'Expense';

	/**
	 * @var string
	 */
	private const RECEIPT_EXTRACTION_SCHEMA = 'ReceiptExtraction';

	/**
	 * ReceiptExtraction statuses that count as "active" for the
	 * at-most-one-per-expenseId invariant (design.md D3).
	 *
	 * @var string[]
	 */
	private const ACTIVE_STATUSES = ['pending', 'extracted'];

	/**
	 * Max rows loaded per register scan.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve one Expense by id.
	 *
	 * @param string $expenseId The Expense id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findExpense(string $expenseId): ?array {
		foreach ($this->loadAll(self::EXPENSE_SCHEMA) as $expense) {
			$id = (string)($expense['id'] ?? $expense['@self']['id'] ?? '');
			if ($id === $expenseId) {
				return $expense;
			}
		}

		return null;
	}//end findExpense()

	/**
	 * The active (pending/extracted) ReceiptExtraction for one Expense, if
	 * any -- the at-most-one-active invariant (design.md D3).
	 *
	 * @param string $expenseId The Expense id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function activeExtractionFor(string $expenseId): ?array {
		foreach ($this->loadAll(self::RECEIPT_EXTRACTION_SCHEMA) as $row) {
			if (trim((string)($row['expenseId'] ?? '')) !== $expenseId) {
				continue;
			}

			if (in_array((string)($row['status'] ?? ''), self::ACTIVE_STATUSES, true) === true) {
				return $row;
			}
		}

		return null;
	}//end activeExtractionFor()

	/**
	 * Every Expense with a non-empty `receiptFile` and no active
	 * (`pending`/`extracted`) `ReceiptExtraction` (design.md D7). Does NOT
	 * pre-filter already-extracted Expenses -- `extractForExpense()`'s own
	 * idempotency pre-check turns those into a no-op, so there is a single
	 * source of truth for "already extracted".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function eligibleBacklogExpenses(): array {
		$out = [];
		foreach ($this->loadAll(self::EXPENSE_SCHEMA) as $expense) {
			$receiptFile = trim((string)($expense['receiptFile'] ?? ''));
			if ($receiptFile === '') {
				continue;
			}

			$id = (string)($expense['id'] ?? $expense['@self']['id'] ?? '');
			if ($id === '' || $this->activeExtractionFor($id) !== null) {
				continue;
			}

			$out[] = $expense;
		}

		return $out;
	}//end eligibleBacklogExpenses()

	/**
	 * Write the mapped fields onto the Expense via a plain field-merge save.
	 * The payload always carries the object's CURRENT `status` value forward
	 * unchanged (OpenRegister's update path is PUT-semantic -- a property
	 * absent from the payload is nulled, see `ReceiptExtractionService`'s
	 * class docblock) -- the caller never sets a DIFFERENT status and never
	 * invokes an `x-openregister-lifecycle` transition.
	 *
	 * @param array<string, mixed> $existing The current Expense (pre-write).
	 * @param array<string, mixed> $writes The field values to write (never `status`).
	 *
	 * @return array<string, mixed> The saved Expense, normalised to an array.
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-005
	 */
	public function saveExpense(array $existing, array $writes): array {
		$id = (string)($existing['id'] ?? $existing['@self']['id'] ?? '');

		$payload = array_merge($existing, $writes);
		unset($payload['@self']);

		$saved = $this->objectService()->saveObject(
			object: $payload,
			register: $this->register(),
			schema: self::EXPENSE_SCHEMA,
			uuid: ($id === '' ? null : $id),
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray($saved);
	}//end saveExpense()

	/**
	 * Create a new ReceiptExtraction.
	 *
	 * @param array<string, mixed> $fields The object fields.
	 *
	 * @return array<string, mixed> The created object, normalised to an array.
	 */
	public function createReceiptExtraction(array $fields): array {
		$created = $this->objectService()->saveObject(
			object: $fields,
			register: $this->register(),
			schema: self::RECEIPT_EXTRACTION_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray($created);
	}//end createReceiptExtraction()

	/**
	 * Update an existing ReceiptExtraction by merging $fields onto it.
	 *
	 * @param array<string, mixed> $existing The current ReceiptExtraction.
	 * @param array<string, mixed> $fields The fields to overwrite.
	 *
	 * @return array<string, mixed> The saved object, normalised to an array.
	 */
	public function saveReceiptExtraction(array $existing, array $fields): array {
		$id = (string)($existing['id'] ?? $existing['@self']['id'] ?? '');

		$payload = array_merge($existing, $fields);
		unset($payload['@self']);

		$saved = $this->objectService()->saveObject(
			object: $payload,
			register: $this->register(),
			schema: self::RECEIPT_EXTRACTION_SCHEMA,
			uuid: ($id === '' ? null : $id),
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray($saved);
	}//end saveReceiptExtraction()

	/**
	 * Load all objects of a schema (capped), as plain arrays.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('ReceiptExtractionRepository: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end normaliseRows()

	/**
	 * Normalise a single ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get(self::OBJECT_SERVICE_FQCN);
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
