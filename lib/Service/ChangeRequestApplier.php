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
	 * @param RecordStoreInterface $gateway Reads and writes humaniq's register.
	 * @param InternalWriteMarker  $marker  Marks the apply write as humaniq's own.
	 * @param ITimeFactory         $time    Now.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
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
		if ($this->applicable($request) === false) {
			return false;
		}

		$run = $this->applicationOf(requestId: $requestId, request: $request);
		if ($run->stale !== []) {
			$this->refuseStale($run);
			return false;
		}

		$this->writeApplied($run);

		return true;
	}//end apply()

	/**
	 * Whether the request is approved and neither applied nor refused yet.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return bool
	 */
	private function applicable(array $request): bool {
		return ($request['status'] ?? null) === 'goedgekeurd' && $this->text($request['appliedAt'] ?? null) === null && $this->text($request['applyError'] ?? null) === null;
	}//end applicable()

	/**
	 * The request, its employee and the fields found stale.
	 *
	 * @param string               $requestId The request id.
	 * @param array<string, mixed> $request   The request.
	 *
	 * @return ChangeApplication
	 */
	private function applicationOf(string $requestId, array $request): ChangeApplication {
		$run = new ChangeApplication();
		$run->requestId = $requestId;
		$run->record = $this->withoutIdentity($request);
		$run->employeeId = (string)($request['employeeId'] ?? '');
		$run->employee = $this->gateway->findObjectData($run->employeeId, 'Employee');
		$run->changes = $this->mapOf($request['changes'] ?? null);
		$run->stale = $this->staleFields(run: $run, previous: $this->mapOf($request['previousValues'] ?? null));

		return $run;
	}//end applicationOf()

	/**
	 * A list or map value, or empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<array-key, mixed>
	 */
	private function mapOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end mapOf()

	/**
	 * Store on the request why it was not applied.
	 *
	 * @param ChangeApplication $run The application.
	 *
	 * @return void
	 */
	private function refuseStale(ChangeApplication $run): void {
		$record = $run->record;
		$record['applyError'] = 'Niet doorgevoerd: ' . implode(', ', $run->stale) . ' is gewijzigd nadat het verzoek werd gedaan. Doe een nieuw verzoek.';
		$this->marker->runInternal(fn () => $this->gateway->save($record, self::SCHEMA, $run->requestId));
	}//end refuseStale()

	/**
	 * Write the changes to the employee and mark the request applied.
	 *
	 * @param ChangeApplication $run The application.
	 *
	 * @return void
	 */
	private function writeApplied(ChangeApplication $run): void {
		$record = $run->record;
		$record['appliedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->marker->runInternal(
			function () use ($run, $record): void {
				$this->gateway->save(array_merge($this->withoutIdentity((array)$run->employee), $run->changes), 'Employee', $run->employeeId);
				$this->gateway->save($record, self::SCHEMA, $run->requestId);
			}
		);
	}//end writeApplied()

	/**
	 * The fields whose stored value is no longer the one the request saw.
	 *
	 * @param ChangeApplication    $run      The application: the stored employee and the proposed values.
	 * @param array<string, mixed> $previous The values at request time.
	 *
	 * @return list<string>
	 */
	private function staleFields(ChangeApplication $run, array $previous): array {
		$stale = [];
		foreach (array_keys($run->changes) as $field) {
			if ($run->employee === null || $this->same($run->employee[$field] ?? null, $previous[$field] ?? null) === false) {
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
