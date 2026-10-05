<?php

/**
 * SurveyAudience
 *
 * Who an engagement survey goes to (talent-engagement-surveys): the active
 * employees of the survey's administration, narrowed to chosen departments
 * with their teams or to one contract type, and an employee's department and
 * contract type on a day. Split from SurveyService so each class keeps one
 * concern.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The audience of a survey.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
class SurveyAudience {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway       Reads employees, placements and contracts.
	 * @param UnitMembership       $membership    Row ids and unit subtrees.
	 * @param OrgResolutionService $orgResolution Whether a placement is active on a day.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly UnitMembership $membership,
		private readonly OrgResolutionService $orgResolution,
	) {
	}//end __construct()

	/**
	 * The active employees the survey goes to.
	 *
	 * @param array<string, mixed> $survey The survey.
	 * @param string               $today  The day.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	public function employeesFor(array $survey, string $today): array {
		$administration = trim((string)($survey['administrationId'] ?? ''));
		$scope = (string)($survey['scope'] ?? 'everyone');
		$audience = ($scope === 'orgUnits') ? $this->audienceUnits(survey: $survey) : [];
		$out = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			if ($this->isActive(employee: $employee, today: $today) === false
				|| ($administration !== '' && $administration !== trim((string)($employee['administrationId'] ?? '')))
			) {
				continue;
			}

			if ($this->matchesScope(survey: $survey, employeeId: $this->membership->rowId($employee), audience: $audience, today: $today) === false) {
				continue;
			}

			$out[] = $employee;
		}

		return $out;
	}//end employeesFor()

	/**
	 * Whether an active employee falls in the survey's scope: everyone, the
	 * chosen departments with their teams, or one contract type.
	 *
	 * @param array<string, mixed> $survey     The survey.
	 * @param string               $employeeId The employee.
	 * @param list<string>         $audience   The chosen departments with their teams.
	 * @param string               $today      The day.
	 *
	 * @return bool
	 */
	private function matchesScope(array $survey, string $employeeId, array $audience, string $today): bool {
		$scope = (string)($survey['scope'] ?? 'everyone');
		if ($scope === 'orgUnits') {
			return in_array($this->unitOf(employeeId: $employeeId, today: $today), $audience, true);
		}

		if ($scope === 'contractType') {
			return $this->contractTypeOf(employeeId: $employeeId, today: $today) === (string)($survey['contractType'] ?? '');
		}

		return true;
	}//end matchesScope()

	/**
	 * Whether the employee is in service on the day.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param string               $today    The day.
	 *
	 * @return bool
	 */
	private function isActive(array $employee, string $today): bool {
		$start = trim((string)($employee['startDate'] ?? ''));
		$end = trim((string)($employee['endDate'] ?? ''));

		return ($start === '' || $start <= $today) && ($end === '' || $end >= $today);
	}//end isActive()

	/**
	 * The chosen departments with their teams.
	 *
	 * @param array<string, mixed> $survey The survey.
	 *
	 * @return list<string>
	 */
	private function audienceUnits(array $survey): array {
		$units = $this->gateway->loadAll('OrgUnit');
		$out = [];
		foreach ((array)($survey['orgUnitIds'] ?? []) as $unitId) {
			$out = array_merge($out, $this->membership->subtree((string)$unitId, $units));
		}

		return array_values(array_unique($out));
	}//end audienceUnits()

	/**
	 * The employee's department on the day: the first active placement.
	 *
	 * @param string $employeeId The employee.
	 * @param string $today      The day.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	public function unitOf(string $employeeId, string $today): ?string {
		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $today) === true) {
				return (string)($assignment['orgUnitId'] ?? '');
			}
		}

		return null;
	}//end unitOf()

	/**
	 * The type of the employee's contract on the day.
	 *
	 * @param string $employeeId The employee.
	 * @param string $today      The day.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	public function contractTypeOf(string $employeeId, string $today): ?string {
		foreach ($this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]) as $contract) {
			$start = trim((string)($contract['startDate'] ?? ''));
			$end = trim((string)($contract['endDate'] ?? ''));
			if (($start === '' || $start <= $today) && ($end === '' || $end >= $today)) {
				return (string)($contract['type'] ?? '');
			}
		}

		return null;
	}//end contractTypeOf()
}//end class
