<?php

/**
 * Agenda Hours Sources
 *
 * The rows an availability or capacity answer counts for a viewer who sees
 * some employees only as a colleague. Their secondments and interviews stay
 * out, so neither a secondment's hours per week nor an interview's date and
 * length can be read off their planned hours. An interview keeps counting for
 * the participants the viewer sees in full.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Trims secondments and interview participants to the employees seen in full.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
 */
final class AgendaHoursSources {

	/**
	 * The single-participant fields of an interview row.
	 *
	 * @var array<int, string>
	 */
	private const PARTICIPANT_FIELDS = ['employeeId', 'interviewerId', 'participantId'];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $full The employees seen in full.
	 */
	public function __construct(
		private readonly array $full,
	) {

	}//end __construct()

	/**
	 * The sources without secondments and interview participants not seen in
	 * full.
	 *
	 * @param array<string, mixed> $sources The rows the answer reads.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function trim(array $sources): array {
		$sources['secondments'] = array_values(
			array_filter(
				($sources['secondments'] ?? []),
				fn (array $row): bool => $this->seen($row['employeeId'] ?? '')
			)
		);
		$sources['interviews'] = array_map(
			fn (array $row): array => $this->interview($row),
			($sources['interviews'] ?? [])
		);

		return $sources;
	}//end trim()

	/**
	 * One interview row with only the participants seen in full.
	 *
	 * @param array<string, mixed> $row The interview.
	 *
	 * @return array<string, mixed>
	 */
	private function interview(array $row): array {
		foreach (self::PARTICIPANT_FIELDS as $field) {
			if (isset($row[$field]) === true && $this->seen($row[$field]) === false) {
				$row[$field] = '';
			}
		}

		if (is_array($row['interviewerIds'] ?? null) === true) {
			$row['interviewerIds'] = array_values(array_filter($row['interviewerIds'], fn (mixed $id): bool => $this->seen($id)));
		}

		return $row;
	}//end interview()

	/**
	 * Whether an employee id is seen in full.
	 *
	 * @param mixed $employeeId The id.
	 *
	 * @return bool
	 */
	private function seen(mixed $employeeId): bool {
		return in_array(trim((string)$employeeId), $this->full, true);
	}//end seen()

}//end class
