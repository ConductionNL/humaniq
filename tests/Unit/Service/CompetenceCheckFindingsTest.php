<?php

/**
 * Unit tests for the competence findings of a set of roster assignments.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CompetenceCheckService;
use PHPUnit\Framework\TestCase;

/**
 * One finding per missing competence per assignment, in order.
 */
class CompetenceCheckFindingsTest extends TestCase {

	/**
	 * Assignments without a shift, without requirements, without an employee
	 * or date give nothing; a held, an expired, a not-yet-issued and another
	 * employee's competence each answer as they should.
	 *
	 * @return void
	 */
	public function testTheWholeFindings(): void {
		$shifts = [
			'night' => ['requiredCompetences' => ['BHV', 'EHBO']],
			'day' => ['requiredCompetences' => []],
		];
		$competences = [
			['employeeId' => 'emp-1', 'competenceCode' => 'BHV', 'issuedOn' => '2026-01-01', 'validUntil' => '2026-12-31'],
			['employeeId' => 'emp-1', 'competenceCode' => 'EHBO', 'validUntil' => '2026-05-31'],
			['employeeId' => 'emp-2', 'competenceCode' => 'BHV', 'issuedOn' => '2026-06-02'],
			['employeeId' => 'emp-3', 'competenceCode' => 'EHBO'],
		];
		$assignments = [
			['id' => 'a-1', 'shiftId' => 'night', 'employeeId' => 'emp-1', 'date' => '2026-06-01T22:00:00'],
			['@self' => ['id' => 'a-2'], 'shiftId' => ' night ', 'employeeId' => ' emp-2 ', 'date' => '2026-06-01'],
			['id' => 'a-3', 'shiftId' => 'day', 'employeeId' => 'emp-1', 'date' => '2026-06-01'],
			['id' => 'a-4', 'shiftId' => 'gone', 'employeeId' => 'emp-1', 'date' => '2026-06-01'],
			['id' => 'a-5', 'shiftId' => 'night', 'employeeId' => '', 'date' => '2026-06-01'],
			['id' => 'a-6', 'shiftId' => 'night', 'employeeId' => 'emp-1', 'date' => ''],
			['shiftId' => 'night', 'employeeId' => 'emp-3', 'date' => '2026-06-01'],
		];

		$finding = static fn (string $id, string $employee, string $code): array => [
			'kind' => 'competence',
			'objectType' => 'RosterAssignment',
			'objectId' => $id,
			'employeeId' => $employee,
			'date' => '2026-06-01',
			'competence' => $code,
			'severity' => 'mandatory',
			'statement' => 'Medewerker ' . $employee . ' heeft op 2026-06-01 geen geldige bevoegdheid ' . $code . ' voor deze dienst.',
		];
		self::assertSame(
			[$finding('a-1', 'emp-1', 'EHBO'), $finding('a-2', 'emp-2', 'BHV'), $finding('a-2', 'emp-2', 'EHBO'), $finding('', 'emp-3', 'BHV')],
			(new CompetenceCheckService())->findings(assignments: $assignments, shiftsById: $shifts, competences: $competences)
		);
	}//end testTheWholeFindings()
}//end class
