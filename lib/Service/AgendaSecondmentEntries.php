<?php

/**
 * Humaniq AgendaSecondmentEntries
 *
 * Turns active secondments into agenda entries for AgendaComposer
 * (people-secondment-and-side-activities D2), the AgendaBookingEntries shape.
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
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Builds secondment agenda entries.
 *
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-002
 */
class AgendaSecondmentEntries {

	/**
	 * Active secondments as agenda entries (people-secondment-and-side-activities D2).
	 *
	 * One entry over the secondment's days inside the window, carrying its
	 * hours per week, so availability can commit that share of each working day.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-002
	 */
	public function entries(array $sources, array $employees, string $from, string $to): array {
		$entries = [];
		foreach (($sources['secondments'] ?? []) as $secondment) {
			$employeeId = trim((string)($secondment['employeeId'] ?? ''));
			if (in_array($employeeId, $employees, true) === false || trim((string)($secondment['status'] ?? '')) !== 'actief') {
				continue;
			}

			$start = max($from, substr(trim((string)($secondment['startDate'] ?? '')), 0, 10));
			$endDate = substr(trim((string)($secondment['endDate'] ?? '')), 0, 10);
			$end = ($endDate === '') ? $to : min($to, $endDate);
			if ($start === '' || $start > $end) {
				continue;
			}

			$entries[] = [
				'kind' => 'secondment',
				'subjectType' => 'employee',
				'subjectId' => $employeeId,
				'start' => ($start . 'T00:00:00'),
				'end' => ($end . 'T23:59:59'),
				'label' => 'Gedetacheerd: ' . trim((string)($secondment['receivingOrganisation'] ?? '')),
				'hoursPerWeek' => (float)($secondment['hoursPerWeek'] ?? 0),
				'sourceType' => 'Secondment',
				'sourceId' => trim((string)($secondment['id'] ?? '')),
			];
		}

		return $entries;
	}//end entries()

}//end class
