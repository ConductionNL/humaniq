<?php

/**
 * Golden test for AgendaSecondmentEntries: the entries of every shape of secondment.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AgendaSecondmentEntries;
use OCA\Humaniq\Service\AgendaSubject;
use OCA\Humaniq\Service\AgendaWindow;
use PHPUnit\Framework\TestCase;

/**
 * The entries of every shape of secondment.
 */
class AgendaSecondmentEntriesGoldenTest extends TestCase {

	/**
	 * Every shape, for a team and for one person.
	 *
	 * @return void
	 */
	public function testTheEntriesOfEveryShapeOfSecondment(): void {
		$secondments = [
			['id' => ' sec-1 ', 'employeeId' => 'emp-a', 'status' => 'actief', 'startDate' => '2026-05-01', 'endDate' => '2026-07-31T00:00:00Z', 'receivingOrganisation' => ' Gemeente X ', 'hoursPerWeek' => '16'],
			['id' => 'sec-2', 'employeeId' => ' emp-b ', 'status' => ' actief ', 'startDate' => '2026-06-10'],
			['id' => 'sec-3', 'employeeId' => 'emp-a', 'status' => 'beeindigd', 'startDate' => '2026-06-01'],
			['id' => 'sec-4', 'employeeId' => 'emp-c', 'status' => 'actief', 'startDate' => '2026-06-01'],
			['id' => 'sec-5', 'employeeId' => 'emp-a', 'status' => 'actief', 'startDate' => '2026-07-01'],
			['id' => 'sec-6', 'employeeId' => 'emp-b', 'status' => 'actief', 'startDate' => '2026-05-01', 'endDate' => '2026-05-31'],
			['id' => 'sec-7', 'employeeId' => 'emp-b', 'status' => 'actief', 'startDate' => '2026-06-30', 'endDate' => '2026-06-30'],
			['id' => 'sec-8', 'employeeId' => 'emp-a', 'status' => 'actief'],
			['employeeId' => 'emp-a', 'status' => 'actief', 'startDate' => '2026-06-15', 'endDate' => '2026-06-20'],
		];
		$component = new AgendaSecondmentEntries();
		$got = [
			'team' => $component->entries(['secondments' => $secondments], new AgendaWindow(new AgendaSubject('orgUnit', 'unit-1', ['emp-a', 'emp-b']), '2026-06-01', '2026-06-30')),
			'person' => $component->entries(['secondments' => $secondments], new AgendaWindow(new AgendaSubject('employee', 'emp-b'), '2026-06-01', '2026-06-30')),
			'resource' => $component->entries(['secondments' => $secondments], new AgendaWindow(new AgendaSubject('resource', 'room-1'), '2026-06-01', '2026-06-30')),
			'none' => $component->entries([], new AgendaWindow(new AgendaSubject('employee', 'emp-a'), '2026-06-01', '2026-06-30')),
		];

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/agenda-secondment-entries.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
	}//end testTheEntriesOfEveryShapeOfSecondment()
}//end class
