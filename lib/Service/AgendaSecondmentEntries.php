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
	 * @param AgendaWindow $window The subject's employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-002
	 */
	public function entries(array $sources, AgendaWindow $window): array {
		$entries = [];
		foreach (($sources['secondments'] ?? []) as $secondment) {
			$span = ($this->isActiveFor(secondment: $secondment, window: $window) === true) ? $this->spanOf(secondment: $secondment, window: $window) : null;
			if ($span !== null) {
				$entries[] = $this->entryOf(secondment: $secondment, span: $span);
			}
		}

		return $entries;
	}//end entries()

	/**
	 * Whether a secondment is active and of one of the window's employees.
	 *
	 * @param array<string, mixed> $secondment The secondment.
	 * @param AgendaWindow $window The subject and the window.
	 *
	 * @return bool
	 */
	private function isActiveFor(array $secondment, AgendaWindow $window): bool {
		return in_array(trim((string)($secondment['employeeId'] ?? '')), $window->employees, true) === true && trim((string)($secondment['status'] ?? '')) === 'actief';
	}//end isActiveFor()

	/**
	 * The days of a secondment inside the window, or null when none are.
	 *
	 * @param array<string, mixed> $secondment The secondment.
	 * @param AgendaWindow $window The subject and the window.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function spanOf(array $secondment, AgendaWindow $window): array|null {
		$start = max($window->from, $this->day(row: $secondment, field: 'startDate'));
		$endDate = $this->day(row: $secondment, field: 'endDate');
		$end = ($endDate === '') ? $window->to : min($window->to, $endDate);

		return ($start === '' || $start > $end) ? null : [$start, $end];
	}//end spanOf()

	/**
	 * The agenda entry for a secondment over its days in the window.
	 *
	 * @param array<string, mixed> $secondment The secondment.
	 * @param array{0: string, 1: string} $span The first and last day.
	 *
	 * @return array<string, mixed>
	 */
	private function entryOf(array $secondment, array $span): array {
		return [
			'kind' => 'secondment',
			'subjectType' => 'employee',
			'subjectId' => trim((string)($secondment['employeeId'] ?? '')),
			'start' => ($span[0] . 'T00:00:00'),
			'end' => ($span[1] . 'T23:59:59'),
			'label' => 'Gedetacheerd: ' . trim((string)($secondment['receivingOrganisation'] ?? '')),
			'hoursPerWeek' => (float)($secondment['hoursPerWeek'] ?? 0),
			'sourceType' => 'Secondment',
			'sourceId' => trim((string)($secondment['id'] ?? '')),
		];
	}//end entryOf()

	/**
	 * The date part of a field, or ''.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $field The field.
	 *
	 * @return string
	 */
	private function day(array $row, string $field): string {
		return substr(trim((string)($row[$field] ?? '')), 0, 10);
	}//end day()

}//end class
