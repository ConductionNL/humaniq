<?php

/**
 * UWV Notification Data
 *
 * The content of the 42-week notification to UWV
 * (absence-deadlines-and-signals design.md D3): from the administration the
 * name, payroll tax number and KvK number; from the employee the name, BSN
 * and date of birth; from the case the first sick day, the current absence
 * percentage and the work-resumption steps (a date and a percentage each);
 * from the contract covering the first sick day the hours, type and end
 * date. Every field is picked by name, so nothing else on the records, and
 * no medical detail, can travel with it (REQ-VWP-002). Pure: it reads what
 * it is given.
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Picks the 42-week notification's fields.
 *
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
 */
class UwvNotificationData {

	/**
	 * The HrGeneratedDocument type.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE = 'uwv-melding-42-weken';

	/**
	 * Why this case cannot have a notification, or null when it can.
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 *
	 * @return array{status: string, message: string}|null
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
	 */
	public function refusal(array $case): ?array {
		if (trim((string)($case['employeeId'] ?? '')) === '' || trim((string)($case['firstSickDay'] ?? '')) === '') {
			return ['status' => 'refused-not-found', 'message' => 'Ziektegeval niet gevonden of onvolledig.'];
		}

		if ((string)($case['status'] ?? '') === 'hersteld') {
			return ['status' => 'refused-recovered', 'message' => 'De medewerker is hersteld; er is geen 42-wekenmelding nodig.'];
		}

		if (trim((string)($case['uwv42WeekMeldingDone'] ?? '')) !== '') {
			return ['status' => 'refused-already-done', 'message' => 'De 42-wekenmelding is al gedaan.'];
		}

		return null;
	}//end refusal()

	/**
	 * The notification's fields, and only those.
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, mixed>|null $contract The contract covering the first sick day, or null.
	 * @param array<string, mixed> $administration The hrAdministration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
	 */
	public function build(array $case, array $employee, ?array $contract, array $administration): array {
		$resumption = [];
		foreach ((is_array($case['absenceProgression'] ?? null) === true ? $case['absenceProgression'] : []) as $step) {
			$resumption[] = [
				'effectiveFrom' => ($step['effectiveFrom'] ?? null),
				'absencePercentage' => ($step['absencePercentage'] ?? null),
			];
		}

		return [
			'employer' => [
				'name' => ($administration['name'] ?? null),
				'loonheffingennummer' => ($administration['loonheffingennummer'] ?? null),
				'kvkNumber' => ($administration['kvkNumber'] ?? null),
			],
			'employee' => [
				'name' => trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? '')),
				'bsn' => ($employee['bsn'] ?? null),
				'dateOfBirth' => ($employee['dateOfBirth'] ?? null),
			],
			'case' => [
				'firstSickDay' => ($case['firstSickDay'] ?? null),
				'currentAbsencePercentage' => ($case['currentAbsencePercentage'] ?? null),
				'resumption' => $resumption,
			],
			'contract' => [
				'hoursPerWeek' => ($contract['hoursPerWeek'] ?? null),
				'type' => ($contract['type'] ?? null),
				'endDate' => ($contract['endDate'] ?? null),
			],
		];
	}//end build()

	/**
	 * The contract that runs on a date: started on or before it, not ended
	 * before it; the latest-starting one when several do.
	 *
	 * @param array<int, array<string, mixed>> $contracts The employee's contracts.
	 * @param string $onDate ISO date.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
	 */
	public function coveringContract(array $contracts, string $onDate): ?array {
		$found = null;
		foreach ($contracts as $contract) {
			$start = (string)($contract['startDate'] ?? '');
			$end = trim((string)($contract['endDate'] ?? ''));
			$runs = $start !== '' && $start <= $onDate && ($end === '' || $end >= $onDate);
			if ($runs === true && ($found === null || $start > (string)$found['startDate'])) {
				$found = $contract;
			}
		}

		return $found;
	}//end coveringContract()

}//end class
