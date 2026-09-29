<?php

/**
 * Humaniq SideActivityRegister
 *
 * The side activity register behind Employee.nevenwerkzaamhedenGemeld
 * (people-secondment-and-side-activities D3, D4; Ambtenarenwet 2017 art. 9).
 *
 * A report is placed on its employee: filed from Mijn HR it is the caller's
 * own, and only HR may file one for somebody else. The attestation the rule
 * nl-ambtenaar-nevenwerkzaamheden-melding reads is derived from the register:
 * true while the employee has a report in gemeld, akkoord or afgewezen, or a
 * nil report, and false otherwise, so it can no longer be ticked without a
 * report behind it.
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
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Places side activity reports and derives the attestation.
 *
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */
class SideActivityRegister {

	public const ACTIVITY_SLUG = 'sideactivity';

	public const EMPLOYEE_SLUG = 'employee';

	public const FLAG = 'nevenwerkzaamhedenGemeld';

	/**
	 * The states in which a report counts as on file.
	 */
	private const REPORTED_STATES = ['gemeld', 'akkoord', 'afgewezen'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Register reads and writes, past RBAC.
	 * @param HumaniqRoles $roles Who may report for somebody else.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly HumaniqRoles $roles,
	) {

	}//end __construct()

	/**
	 * The lower-case slug of a schema id.
	 *
	 * @param string $schemaId The entity's schema id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function slugOf(string $schemaId): string {
		return strtolower($this->gateway->resolveSchemaSlug($schemaId));
	}//end slugOf()

	/**
	 * The fields to stamp on a new report, or the reason it is refused.
	 *
	 * @param array<string, mixed> $report What was entered.
	 * @param string $userId The caller, '' for a system write.
	 * @param string $today Today (Y-m-d).
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function place(array $report, string $userId, string $today): array {
		$employee = $this->employeeFor(report: $report, userId: $userId);
		if ($employee === null) {
			return ['stamps' => [], 'error' => 'There is no employee record for this side activity.'];
		}

		$own = (string)($employee['nextcloudUserId'] ?? '');
		if ($userId !== '' && $userId !== $own && $this->roles->isHr($userId) === false) {
			return ['stamps' => [], 'error' => 'You can only report your own side activities.'];
		}

		if (($report['noneToReport'] ?? false) !== true && trim((string)($report['description'] ?? '')) === '') {
			return ['stamps' => [], 'error' => 'Describe the activity, or tick Nothing to report.'];
		}

		$employeeId = (string)$employee['id'];

		return [
			'stamps' => [
				'employeeId' => $employeeId,
				'userId' => ($own !== '' ? $own : null),
				'managerUserId' => $this->gateway->uniqueManagerUserIdFor($employeeId, $today),
				'administrationId' => ($report['administrationId'] ?? ($employee['administrationId'] ?? null)),
			],
			'error' => null,
		];
	}//end place()

	/**
	 * Whether the employee has a report on file.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function reported(string $employeeId): bool {
		foreach ($this->gateway->findFiltered('SideActivity', ['employeeId' => $employeeId]) as $report) {
			if (in_array((string)($report['status'] ?? 'gemeld'), self::REPORTED_STATES, true) === true) {
				return true;
			}
		}

		return false;
	}//end reported()

	/**
	 * Bring the employee's attestation in step with the register.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function attest(string $employeeId): void {
		if ($employeeId === '') {
			return;
		}

		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		$reported = $this->reported(employeeId: $employeeId);
		if ($employee === null || ($employee[self::FLAG] ?? false) === $reported) {
			return;
		}

		$employee[self::FLAG] = $reported;
		$this->gateway->save($employee, 'Employee', $employeeId);
	}//end attest()

	/**
	 * The register's value when an update changes the attestation by hand, else null.
	 *
	 * @param array<string, mixed> $new The employee as it will be saved.
	 * @param array<string, mixed> $old The employee as it was.
	 * @param string $employeeId The employee.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function correction(array $new, array $old, string $employeeId): ?bool {
		if (array_key_exists(self::FLAG, $new) === false || (bool)$new[self::FLAG] === (bool)($old[self::FLAG] ?? false)) {
			return null;
		}

		$reported = $this->reported(employeeId: $employeeId);

		return ((bool)$new[self::FLAG] === $reported) ? null : $reported;
	}//end correction()

	/**
	 * The employee a report is for: the named one, else the caller's own record.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param string $userId The caller.
	 *
	 * @return array<string, mixed>|null The employee, with its id.
	 */
	private function employeeFor(array $report, string $userId): ?array {
		$employeeId = trim((string)($report['employeeId'] ?? ''));
		if ($employeeId !== '') {
			$employee = $this->gateway->findObjectData($employeeId, 'Employee');
			return ($employee === null) ? null : array_merge($employee, ['id' => $employeeId]);
		}

		$employee = $this->gateway->findEmployeeByUserId($userId);
		if ($employee === null || trim((string)($employee['id'] ?? '')) === '') {
			return null;
		}

		return $employee;
	}//end employeeFor()

}//end class
