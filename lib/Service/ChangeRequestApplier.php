<?php

/**
 * Humaniq ChangeRequestApplier
 *
 * Writes an approved EmployeeChangeRequest to the employee
 * (people-record-change-approval D4): once, as one internal write, and only
 * while the record still holds the values the request saw. A stale request
 * is marked with the fields that moved and left for HR to redo.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Applies approved employee change requests.
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */
class ChangeRequestApplier {

	/**
	 * The request schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'EmployeeChangeRequest';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads and writes humaniq's register.
	 * @param InternalWriteMarker  $marker  Marks the apply write as humaniq's own.
	 * @param ITimeFactory         $time    Now.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Write an approved request's values to the employee, once.
	 *
	 * @param string               $requestId The request.
	 * @param array<string, mixed> $request   The request as saved.
	 *
	 * @return bool Whether the values were written.
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function apply(string $requestId, array $request): bool {
		if (($request['status'] ?? null) !== 'goedgekeurd' || $this->text($request['appliedAt'] ?? null) !== null || $this->text($request['applyError'] ?? null) !== null) {
			return false;
		}

		$employeeId = (string)($request['employeeId'] ?? '');
		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		$changes = (is_array($request['changes'] ?? null) === true ? $request['changes'] : []);
		$previous = (is_array($request['previousValues'] ?? null) === true ? $request['previousValues'] : []);
		$stale = $this->staleFields($employee, $changes, $previous);

		$record = $this->withoutIdentity($request);
		if ($stale !== []) {
			$record['applyError'] = 'Niet doorgevoerd: ' . implode(', ', $stale) . ' is gewijzigd nadat het verzoek werd gedaan. Doe een nieuw verzoek.';
			$this->marker->runInternal(fn () => $this->gateway->save($record, self::SCHEMA, $requestId));
			return false;
		}

		$record['appliedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->marker->runInternal(
			function () use ($employee, $changes, $employeeId, $record, $requestId): void {
				$this->gateway->save(array_merge($this->withoutIdentity((array)$employee), $changes), 'Employee', $employeeId);
				$this->gateway->save($record, self::SCHEMA, $requestId);
			}
		);

		return true;
	}//end apply()

	/**
	 * The fields whose stored value is no longer the one the request saw.
	 *
	 * @param array<string, mixed>|null $employee The stored employee.
	 * @param array<string, mixed>      $changes  The proposed values.
	 * @param array<string, mixed>      $previous The values at request time.
	 *
	 * @return list<string>
	 */
	private function staleFields(?array $employee, array $changes, array $previous): array {
		$stale = [];
		foreach (array_keys($changes) as $field) {
			if ($employee === null || $this->same($employee[$field] ?? null, $previous[$field] ?? null) === false) {
				$stale[] = (string)$field;
			}
		}

		return $stale;
	}//end staleFields()

	/**
	 * Whether two stored values are the same value.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 *
	 * @return bool
	 */
	private function same(mixed $left, mixed $right): bool {
		if (is_numeric($left) === true && is_numeric($right) === true) {
			return (float)$left === (float)$right;
		}

		return $this->text($left) === $this->text($right);
	}//end same()

	/**
	 * A row without its id and metadata, for a save by id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutIdentity(array $row): array {
		unset($row['id'], $row['@self']);

		return $row;
	}//end withoutIdentity()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$trimmed = trim((string)$value);

		return $trimmed === '' ? null : $trimmed;
	}//end text()

}//end class
