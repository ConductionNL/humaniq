<?php

/**
 * Humaniq HoursRegisterGateway
 *
 * The hours-process listeners' one door to OpenRegister: object reads,
 * filtered queries, schema-slug resolution and the org-chain index building
 * the stamping listeners share (hours-process-redesign Decisions 3-5).
 * Extracted so each listener carries only its DECISION logic — the plumbing
 * (lazy container lookups, entity-to-array normalisation, index shapes)
 * lives once, here.
 *
 * Every read/write goes through a CLONED ObjectService: the shared
 * instance's register/schema context is live state of whatever outer save a
 * pre-save listener runs inside, and mutating it mid-save poisons the outer
 * call's post-save phase (the openbuild#75 stale-context lesson).
 *
 * The index builders return the exact shapes
 * `RuleAuditService::buildRelatedContext()` feeds the
 * nl-mss-manager-consistency audit, so {@see OrgResolutionService} resolves
 * identically for the stamp and the audit.
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Shared OpenRegister plumbing for the hours-process listeners.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
class HoursRegisterGateway {

	/**
	 * Upper bound for lookup queries (dev-stage volumes; the
	 * AdministrationService findAll-then-filter precedent).
	 *
	 * @var int
	 */
	private const LOOKUP_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OpenRegister lookups).
	 * @param SettingsService $settingsService The humaniq settings (register slug).
	 * @param OrgResolutionService $orgResolution The shared org-chain resolver (the audit's code path).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly OrgResolutionService $orgResolution,
	) {

	}//end __construct()

	/**
	 * Load one object's payload by uuid, or null when unresolvable. The
	 * entity's uuid is echoed into `id` so index shapes stay uniform.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	public function findObjectData(string $uuid, string $schema): ?array {
		try {
			$entity = $this->objects()->find(
				id: $uuid,
				register: $this->settingsService->getRegisterSlug(),
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return null;
		}

		$id = (string)$entity->getUuid();
		if ($id !== '' && isset($data['id']) === false) {
			$data['id'] = $id;
		}

		return $data;
	}//end findObjectData()

	/**
	 * Every row of a schema, as arrays (the AssetDialectMigrationService
	 * loadAll precedent).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function loadAll(string $schema): array {
		return $this->query($schema, []);
	}//end loadAll()

	/**
	 * Rows of a schema matching top-level equality filters. Pushed-down AND
	 * re-checked in PHP — belt and braces against filter-grammar drift.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Top-level equality filters.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	public function findFiltered(string $schema, array $filters): array {
		$matches = [];
		foreach ($this->query($schema, $filters) as $row) {
			if ($this->matchesFilters($row, $filters) === true) {
				$matches[] = $row;
			}
		}

		return $matches;
	}//end findFiltered()

	/**
	 * Create or update one object.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 * @param string $schema The schema slug.
	 * @param string|null $uuid The uuid (null creates).
	 *
	 * @return object The saved ObjectEntity.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	public function save(array $payload, string $schema, ?string $uuid = null): object {
		return $this->objects()->saveObject(
			object: $payload,
			register: $this->settingsService->getRegisterSlug(),
			schema: $schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The employee's UNIQUE resolved manager Nextcloud user id on a date, or
	 * null (chain unresolved, or ambiguous — never guessed). The chain
	 * itself is {@see OrgResolutionService} — the exact code path the
	 * nl-mss-manager-consistency audit evaluates, fed with live indexes.
	 *
	 * @param string $employeeId The employee id.
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return string|null The manager's Nextcloud user id, or null.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function uniqueManagerUserIdFor(string $employeeId, string $onDate): ?string {
		return $this->orgResolution->uniqueOrNull(
			$this->orgResolution->resolveManagerUserIds(
				employeeId: $employeeId,
				assignmentsByEmployeeId: $this->assignmentsIndexFor($employeeId),
				unitsById: $this->indexById($this->loadAll('OrgUnit')),
				employeesById: $this->indexById($this->loadAll('Employee')),
				onDate: $onDate
			)
		);
	}//end uniqueManagerUserIdFor()

	/**
	 * The employee's UNIQUE resolved cost centre on a date, or null (chain
	 * unresolved, or ambiguous — never guessed).
	 *
	 * @param string $employeeId The employee id.
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return string|null The cost centre, or null.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/employer-hourly-cost-rate/spec.md#Requirement:-Cost-allocation-references-live-on-the-time-entry-and-are-never-employee-typed
	 */
	public function uniqueCostCenterFor(string $employeeId, string $onDate): ?string {
		return $this->orgResolution->uniqueOrNull(
			$this->orgResolution->resolveCostCenters(
				employeeId: $employeeId,
				assignmentsByEmployeeId: $this->assignmentsIndexFor($employeeId),
				unitsById: $this->indexById($this->loadAll('OrgUnit')),
				onDate: $onDate
			)
		);
	}//end uniqueCostCenterFor()

	/**
	 * The employee's OrgAssignment rows, in the shared byEmployeeId shape.
	 *
	 * @param string $employeeId The employee id.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The index.
	 */
	private function assignmentsIndexFor(string $employeeId): array {
		$index = [];
		foreach ($this->loadAll('OrgAssignment') as $assignment) {
			if (trim((string)($assignment['employeeId'] ?? '')) === $employeeId) {
				$index[$employeeId][] = $assignment;
			}
		}

		return $index;
	}//end assignmentsIndexFor()

	/**
	 * The Employee whose `nextcloudUserId` equals the given uid, or null —
	 * the mijn-hr account link (the PayrollController::resolveOwnEmployee()
	 * precedent: findAll-then-filter).
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return array<string, mixed>|null The Employee payload, or null.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function findEmployeeByUserId(string $uid): ?array {
		if (trim($uid) === '') {
			return null;
		}

		foreach ($this->loadAll('Employee') as $employee) {
			if (trim((string)($employee['nextcloudUserId'] ?? '')) === $uid) {
				return $employee;
			}
		}

		return null;
	}//end findEmployeeByUserId()

	/**
	 * Key rows by their id.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>> The byId index.
	 */
	private function indexById(array $rows): array {
		$index = [];
		foreach ($rows as $row) {
			$id = trim((string)($row['id'] ?? $row['@self']['id'] ?? ''));
			if ($id !== '') {
				$index[$id] = $row;
			}
		}

		return $index;
	}//end indexById()

	/**
	 * Resolve a schema id to its slug via OpenRegister's SchemaMapper
	 * (defence in depth under the subscription-level schema filter — the
	 * TimesheetApprovalListener precedent).
	 *
	 * @param string $schemaId The schema id/uuid carried by an object.
	 *
	 * @return string The slug, or '' when unresolvable.
	 *
	 * @spec exclude cross-cutting OpenRegister plumbing (schema-slug resolution, the TimesheetApprovalListener precedent) shared by every hours-process listener; no single requirement owns it
	 */
	public function resolveSchemaSlug(string $schemaId): string {
		if ($schemaId === '') {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			return (string)$schemaMapper->find($schemaId)->getSlug();
		} catch (\Throwable $e) {
			return '';
		}
	}//end resolveSchemaSlug()

	/**
	 * Run one findAll against a schema.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Optional pushed-down filters.
	 *
	 * @return array<int, array<string, mixed>> The rows, as arrays.
	 */
	private function query(string $schema, array $filters): array {
		$config = ['limit' => self::LOOKUP_LIMIT];
		if ($filters !== []) {
			$config['filters'] = $filters;
		}

		$rows = $this->objects()
			->setRegister($this->settingsService->getRegisterSlug())
			->setSchema($schema)
			->findAll($config, false, false);

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$data = $this->toArray($row);
			if ($data !== []) {
				$out[] = $data;
			}
		}

		return $out;
	}//end query()

	/**
	 * Whether a row satisfies every top-level equality filter.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool True on a full match.
	 */
	private function matchesFilters(array $row, array $filters): bool {
		foreach ($filters as $key => $value) {
			if (trim((string)($row[$key] ?? '')) !== trim((string)$value)) {
				return false;
			}
		}

		return true;
	}//end matchesFilters()

	/**
	 * A CLONED OpenRegister ObjectService — never the shared instance.
	 *
	 * @return object The cloned ObjectService.
	 */
	private function objects(): object {
		// ADR-083: establish availability before reaching. class_exists()
		// answers the same question the container would otherwise have
		// answered fatally (the AssetDialectMigrationService precedent).
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return clone $service;
	}//end objects()

	/**
	 * Normalise a findAll row to an array.
	 *
	 * @param mixed $row The row (array or ObjectEntity).
	 *
	 * @return array<string, mixed> The payload array.
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

}//end class
