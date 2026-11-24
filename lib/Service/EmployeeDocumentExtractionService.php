<?php

/**
 * Employee document extraction
 *
 * Read document on an onboarding case: every document HR attached to the case
 * that is not read yet goes to filinq as an employment contract or an
 * identity document. The values read, their confidence and the fields written
 * are recorded on the EmployeeDocumentExtraction; only empty employee fields
 * are filled. Contract values stay on the record until HR makes the contract
 * from it, at most one per document. filinq is called duck-typed, by class
 * name; without it the attempt is recorded as skipped and nothing is written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Humaniq\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\Loonaangifte\PersonPart;
use OCA\Humaniq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reads a new hire's documents into their record.
 *
 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
 */
class EmployeeDocumentExtractionService {

	/**
	 * filinq's document contract, relative to its namespace (filinq#2323).
	 */
	private const DOCUMENT_SERVICE = 'Service\EmployeeDocumentExtractionService';

	/**
	 * The extraction records.
	 */
	private const SCHEMA = 'EmployeeDocumentExtraction';

	/**
	 * The employee fields each document type may fill, by the name filinq reads.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EMPLOYEE_FIELDS = [
		'identity-document' => ['firstName' => 'firstName', 'lastName' => 'lastName', 'dateOfBirth' => 'dateOfBirth', 'personalNumber' => 'bsn'],
		'employment-contract' => ['firstName' => 'firstName', 'lastName' => 'lastName'],
	];

	/**
	 * @param ContainerInterface   $container The server container, to find filinq.
	 * @param RecordStoreInterface $store     Reads and saves the records.
	 * @param HumaniqRoles         $roles     Who is HR.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RecordStoreInterface $store,
		private readonly HumaniqRoles $roles,
	) {
	}//end __construct()

	/**
	 * Read every document of the case that is not read yet.
	 *
	 * The answer is `{status, message, documents}`: `read` with each
	 * document's id, status and message, `nothing-to-read`, or `forbidden`.
	 *
	 * @param array<string, mixed> $onboarding The onboarding case, with `id`.
	 * @param string               $userId     Who asked.
	 *
	 * @return array{status: string, message: string, documents: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
	 */
	public function read(array $onboarding, string $userId): array {
		if ($this->roles->isHr($userId) === false) {
			return ['status' => 'forbidden', 'message' => 'Only HR or an administrator can have documents read.', 'documents' => []];
		}

		$unread = array_values(array_filter($this->documentsOf(onboarding: $onboarding), static fn (array $row): bool => (($row['status'] ?? 'pending') !== 'extracted')));
		if ($unread === []) {
			return ['status' => 'nothing-to-read', 'message' => 'Every document of this case is read. Add a document to read another one.', 'documents' => []];
		}

		$documents = [];
		foreach ($unread as $extraction) {
			$saved = $this->readOne(extraction: $extraction, onboarding: $onboarding, userId: $userId);
			$documents[] = ['id' => self::idOf(row: $saved), 'docType' => $saved['docType'] ?? '', 'status' => $saved['status'], 'message' => $saved['message'], 'appliedFields' => $saved['appliedFields']];
		}

		return ['status' => 'read', 'message' => 'The documents were read. Check every value before the checklist moves on.', 'documents' => $documents];
	}//end read()

	/**
	 * Make the employment contract from the case's read contract document.
	 *
	 * The answer is `{status, message, contractId}`: `created`, or
	 * `forbidden`, `no-document` (no read contract) or `already-made` (each
	 * read contract already made one).
	 *
	 * @param array<string, mixed> $onboarding The onboarding case, with `id`.
	 * @param string               $userId     Who asked.
	 *
	 * @return array{status: string, message: string, contractId: string|null}
	 *
	 * @spec openspec/changes/hiring-document-to-employee/specs/hire-to-employee/spec.md#REQ-HTE-003
	 */
	public function contractFrom(array $onboarding, string $userId): array {
		if ($this->roles->isHr($userId) === false) {
			return ['status' => 'forbidden', 'message' => 'Only HR or an administrator can make a contract from a document.', 'contractId' => null];
		}

		$contracts = array_values(array_filter($this->documentsOf(onboarding: $onboarding), static fn (array $row): bool => (($row['docType'] ?? '') === 'employment-contract' && ($row['status'] ?? '') === 'extracted')));
		if ($contracts === []) {
			return ['status' => 'no-document', 'message' => 'There is no read employment contract on this case. Add the contract and read it first.', 'contractId' => null];
		}

		foreach ($contracts as $extraction) {
			if (trim((string)($extraction['contractId'] ?? '')) === '') {
				return $this->makeContract(extraction: $extraction, onboarding: $onboarding);
			}
		}

		return ['status' => 'already-made', 'message' => 'A contract was already made from every read contract document of this case.', 'contractId' => (string)$contracts[0]['contractId']];
	}//end contractFrom()

	/**
	 * Read one document and record the outcome; write the empty employee
	 * fields it fills.
	 *
	 * @param array<string, mixed> $extraction The extraction record.
	 * @param array<string, mixed> $onboarding The onboarding case.
	 * @param string               $userId     Who asked.
	 *
	 * @return array<string, mixed> The saved record.
	 */
	private function readOne(array $extraction, array $onboarding, string $userId): array {
		$employeeId = trim((string)($onboarding['employeeId'] ?? $extraction['employeeId'] ?? ''));
		$outcome = $this->extract(extraction: $extraction, userId: $userId);
		$record = ['status' => $outcome['status'], 'message' => $outcome['message'], 'extractedFields' => $outcome['fields'], 'appliedFields' => [], 'notAppliedFields' => []];

		if ($outcome['status'] === 'extracted' && $employeeId !== '') {
			$record = array_merge($record, $this->fillEmployee(employeeId: $employeeId, docType: (string)($extraction['docType'] ?? ''), fields: $outcome['fields']));
		}

		$saved = array_merge($extraction, $record, ['employeeId' => ($employeeId === '' ? null : $employeeId), 'readAt' => gmdate('Y-m-d\TH:i:s\Z'), 'readBy' => $userId]);
		unset($saved['@self']);
		$this->store->save($saved, self::SCHEMA, self::idOf(row: $extraction));

		return $saved;
	}//end readOne()

	/**
	 * filinq's answer for one document, never throwing.
	 *
	 * @param array<string, mixed> $extraction The extraction record.
	 * @param string               $userId     Who asked.
	 *
	 * @return array{status: string, message: string, fields: array<string, mixed>}
	 */
	private function extract(array $extraction, string $userId): array {
		$fileId = self::fileIdOf(value: ($extraction['documentFile'] ?? null));
		if ($fileId === '') {
			return ['status' => 'unreadable', 'message' => 'No document is attached. Attach the file and read it again.', 'fields' => []];
		}

		$filinq = FleetAppId::getService($this->container, 'filinq', self::DOCUMENT_SERVICE);
		if ($filinq === null || method_exists($filinq, 'extractEmployeeDocument') === false) {
			return ['status' => 'skipped-no-docudesk', 'message' => 'Reading a document needs filinq, which is not installed. Type the values instead.', 'fields' => []];
		}

		try {
			$answer = (array)$filinq->extractEmployeeDocument(['fileId' => (int)$fileId, 'docType' => (string)($extraction['docType'] ?? '')], $userId);
		} catch (Throwable $e) {
			return ['status' => 'failed', 'message' => 'The document could not be read (' . $e->getMessage() . '). Type the values instead.', 'fields' => []];
		}

		if (($answer['status'] ?? '') !== 'extracted') {
			return ['status' => 'unreadable', 'message' => (string)($answer['message'] ?? 'The document could not be read.'), 'fields' => []];
		}

		return ['status' => 'extracted', 'message' => (string)($answer['message'] ?? 'The document was read.'), 'fields' => (array)($answer['fields'] ?? [])];
	}//end extract()

	/**
	 * Write the values into the employee's empty fields, carrying every other
	 * field (a save replaces the whole object); a field HR filled is listed
	 * as read but not applied.
	 *
	 * @param string               $employeeId The employee.
	 * @param string               $docType    The document type.
	 * @param array<string, mixed> $fields     filinq's values.
	 *
	 * @return array{appliedFields: list<string>, notAppliedFields: list<string>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PersonPart::elfproef is the pure BSN check the wage tax return uses.
	 */
	private function fillEmployee(string $employeeId, string $docType, array $fields): array {
		$employee = $this->store->findObjectData($employeeId, 'Employee');
		if ($employee === null) {
			return ['appliedFields' => [], 'notAppliedFields' => []];
		}

		$writes = [];
		$notApplied = [];
		foreach ((self::EMPLOYEE_FIELDS[$docType] ?? []) as $read => $target) {
			$value = trim((string)($fields[$read]['value'] ?? ''));
			if ($value === '' || ($target === 'bsn' && PersonPart::elfproef($value) === false)) {
				continue;
			}

			if (trim((string)($employee[$target] ?? '')) !== '') {
				$notApplied[] = $target;
				continue;
			}

			$writes[$target] = $value;
		}

		if ($writes !== []) {
			$saved = array_merge($employee, $writes);
			unset($saved['@self']);
			$this->store->save($saved, 'Employee', $employeeId);
		}

		return ['appliedFields' => array_keys($writes), 'notAppliedFields' => $notApplied];
	}//end fillEmployee()

	/**
	 * Make the contract from a read contract document and link it.
	 *
	 * @param array<string, mixed> $extraction The read contract document.
	 * @param array<string, mixed> $onboarding The onboarding case.
	 *
	 * @return array{status: string, message: string, contractId: string|null}
	 */
	private function makeContract(array $extraction, array $onboarding): array {
		$read = (array)($extraction['extractedFields'] ?? []);
		$value = static fn (string $name): mixed => ($read[$name]['value'] ?? null);
		$employeeId = trim((string)($onboarding['employeeId'] ?? $extraction['employeeId'] ?? ''));
		$contract = array_filter(
			[
				'employeeId' => $employeeId,
				'type' => (in_array($value('contractType'), ['permanent', 'temporary'], true) === true ? $value('contractType') : 'permanent'),
				'writtenContract' => true,
				'startDate' => ($value('startDate') ?? ($onboarding['startDate'] ?? null)),
				'endDate' => $value('endDate'),
				'hoursPerWeek' => (is_numeric($value('hoursPerWeek')) === true ? (float)$value('hoursPerWeek') : null),
				'hourlyWage' => (is_numeric($value('hourlyWage')) === true ? (float)$value('hourlyWage') : null),
				'administrationId' => ($onboarding['administrationId'] ?? null),
			],
			static fn (mixed $field): bool => ($field !== null && $field !== '')
		);
		if ($employeeId === '' || isset($contract['startDate']) === false) {
			return ['status' => 'no-document', 'message' => 'The contract needs an employee and a start date. Fill them on the case first.', 'contractId' => null];
		}

		$made = $this->store->save($contract, 'EmploymentContract');
		$contractId = (string)(method_exists($made, 'getUuid') === true ? $made->getUuid() : ($made->id ?? ''));
		$linked = array_merge($extraction, ['contractId' => $contractId]);
		unset($linked['@self']);
		$this->store->save($linked, self::SCHEMA, self::idOf(row: $extraction));

		return ['status' => 'created', 'message' => 'The contract was made from the document. Check it before it is signed.', 'contractId' => $contractId];
	}//end makeContract()

	/**
	 * The case's documents.
	 *
	 * @param array<string, mixed> $onboarding The onboarding case.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function documentsOf(array $onboarding): array {
		return $this->store->findFiltered(self::SCHEMA, ['onboardingId' => self::idOf(row: $onboarding)]);
	}//end documentsOf()

	/**
	 * A record's id.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * The id of an attached file, as digits, or ''.
	 *
	 * @param mixed $value The file property: an id or the rendered file object.
	 *
	 * @return string
	 */
	private static function fileIdOf(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['fileId'] ?? null);
		}

		$value = trim((string)(is_scalar($value) === true ? $value : ''));

		return (ctype_digit($value) === true ? $value : '');
	}//end fileIdOf()
}//end class
