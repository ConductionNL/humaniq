<?php

/**
 * Agenda Window
 *
 * One agenda request: the subject, the employees it stands for, and the first
 * and last day.
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
 * The subject and the days of one agenda.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */
final class AgendaWindow {

	/**
	 * The employees the subject stands for.
	 *
	 * @var array<int, string>
	 */
	public readonly array $employees;

	/**
	 * @param AgendaSubject $subject Whose agenda it is.
	 * @param string $from First day, inclusive (ISO date).
	 * @param string $to Last day, inclusive (ISO date).
	 */
	public function __construct(
		public readonly AgendaSubject $subject,
		public readonly string $from,
		public readonly string $to,
	) {
		$this->employees = $subject->employees();

	}//end __construct()
}//end class
