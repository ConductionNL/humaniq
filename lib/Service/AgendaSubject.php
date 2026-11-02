<?php

/**
 * Agenda Subject
 *
 * Whose agenda is composed: one employee, an org unit with its members, or
 * a resource.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The subject of one agenda and the employees it stands for.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */
final class AgendaSubject {

	/**
	 * @param string $type `employee`, `orgUnit` or `resource`.
	 * @param string $id The subject's id.
	 * @param array<int, mixed> $memberEmployeeIds The employees in the org unit, when the subject is one.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
		private readonly array $memberEmployeeIds = [],
	) {

	}//end __construct()

	/**
	 * The employees this subject stands for.
	 *
	 * @return array<int, string> The employee ids, empty for a resource.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function employees(): array {
		if ($this->type === 'employee') {
			return [$this->id];
		}

		if ($this->type === 'orgUnit') {
			return array_values(array_unique(array_map('strval', $this->memberEmployeeIds)));
		}

		return [];

	}//end employees()
}//end class
