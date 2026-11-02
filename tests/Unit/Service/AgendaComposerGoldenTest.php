<?php

/**
 * Golden test for AgendaComposer::compose().
 *
 * Composes the agenda of an employee, an org unit (with duplicate and numeric
 * member ids), a resource and an unknown subject kind over every source:
 * rostered shifts with and without planned times or a known shift, approved
 * and pending leave, open and recovered sickness, interviews by any
 * participant, active and ended secondments, bookings by person and of the
 * resource, and cached busy periods; each with rows inside, touching and
 * outside the window, and rows with blank or missing fields. Every agenda is
 * compared with tests/fixtures/golden/agenda-composer.json. Set
 * RECORD_GOLDEN=1 to rewrite that file.
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

use OCA\Humaniq\Service\AgendaComposer;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the agenda.
 *
 * @covers \OCA\Humaniq\Service\AgendaComposer
 * @uses   \OCA\Humaniq\Service\AgendaBookingEntries
 * @uses   \OCA\Humaniq\Service\AgendaSecondmentEntries
 * @uses   \OCA\Humaniq\Service\AgendaSubject
 * @uses   \OCA\Humaniq\Service\AgendaWindow
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */
class AgendaComposerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/agenda-composer.json';

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = (string)json_encode($this->agenda(self::scenarios()[$case]), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode($actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, $flags);
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Compose one scenario's agenda.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function agenda(array $s): array {
		return (new AgendaComposer())->compose(
			self::sources(),
			['subjectType' => $s['type'], 'subjectId' => $s['id'], 'from' => ($s['from'] ?? '2026-06-01'), 'to' => ($s['to'] ?? '2026-06-07'), 'memberEmployeeIds' => ($s['members'] ?? [])]
		);

	}//end agenda()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		return [
			'employee e1' => ['type' => 'employee', 'id' => 'e1'],
			'employee e2' => ['type' => 'employee', 'id' => 'e2'],
			'employee e1 one day' => ['type' => 'employee', 'id' => 'e1', 'from' => '2026-06-03', 'to' => '2026-06-03'],
			'employee e1 later week' => ['type' => 'employee', 'id' => 'e1', 'from' => '2026-06-08', 'to' => '2026-06-14'],
			'employee nobody' => ['type' => 'employee', 'id' => 'e9'],
			'org unit' => ['type' => 'orgUnit', 'id' => 'u1', 'members' => ['e1', 'e2', 'e1', 3]],
			'org unit with member ids ignored for employee' => ['type' => 'employee', 'id' => 'e2', 'members' => ['e1']],
			'empty org unit' => ['type' => 'orgUnit', 'id' => 'u2'],
			'org unit with a blank member' => ['type' => 'orgUnit', 'id' => 'u3', 'members' => ['', 'e2']],
			'resource r1' => ['type' => 'resource', 'id' => 'r1', 'members' => ['e1']],
			'resource r2' => ['type' => 'resource', 'id' => 'r2'],
			'unknown kind' => ['type' => 'team', 'id' => 'e1', 'members' => ['e1']],
		];

	}//end scenarios()

	/**
	 * The rows of every source.
	 *
	 * @return array<string, mixed>
	 */
	private static function sources(): array {
		return [
			'shifts' => [['id' => 's-day', 'name' => 'Dagdienst'], ['id' => 's-night'], ['name' => 'no id']],
			'assignments' => [
				['id' => 'a1', 'employeeId' => 'e1', 'date' => '2026-06-02', 'shiftId' => 's-day', 'plannedStart' => '2026-06-02T07:00:00', 'plannedEnd' => '2026-06-02T15:00:00'],
				['id' => 'a2', 'employeeId' => ' e1 ', 'date' => '2026-06-03T00:00:00', 'shiftId' => 's-night'],
				['id' => 'a3', 'employeeId' => 'e2', 'date' => '2026-06-07', 'shiftId' => 's-unknown', 'plannedStart' => ' '],
				['id' => 'a4', 'employeeId' => 'e1', 'date' => '2026-06-08', 'shiftId' => 's-day'],
				['id' => 'a5', 'employeeId' => 'e1', 'date' => ''],
				['id' => 'a6', 'employeeId' => '3', 'date' => '2026-06-04'],
				['id' => 'a7', 'date' => '2026-06-04'],
			],
			'leaveRequests' => [
				['id' => 'l1', 'employeeId' => 'e1', 'status' => 'approved', 'startDate' => '2026-05-28', 'endDate' => '2026-06-01'],
				['id' => 'l2', 'employeeId' => 'e1', 'status' => 'pending', 'startDate' => '2026-06-02', 'endDate' => '2026-06-02'],
				['id' => 'l3', 'employeeId' => 'e2', 'status' => 'approved', 'startDate' => '2026-06-07T00:00:00', 'endDate' => '2026-06-09'],
				['id' => 'l4', 'employeeId' => 'e2', 'status' => 'approved', 'startDate' => '2026-06-08', 'endDate' => '2026-06-09'],
				['id' => 'l5', 'employeeId' => 'e1', 'status' => 'approved', 'startDate' => '', 'endDate' => '2026-06-03'],
				['id' => 'l6', 'employeeId' => 'e1', 'status' => ' approved ', 'startDate' => '2026-05-01', 'endDate' => '2026-05-31'],
			],
			'sickLeaveCases' => [
				['id' => 'c1', 'employeeId' => 'e1', 'firstSickDay' => '2026-06-04', 'status' => 'open', 'reason' => 'never copied'],
				['id' => 'c2', 'employeeId' => 'e2', 'firstSickDay' => '2026-05-20', 'status' => 'hersteld', 'recoveredDate' => '2026-06-02'],
				['id' => 'c3', 'employeeId' => 'e2', 'firstSickDay' => '2026-05-01', 'status' => 'hersteld', 'recoveredDate' => '2026-05-31'],
				['id' => 'c4', 'employeeId' => 'e2', 'firstSickDay' => '2026-05-01', 'status' => 'hersteld'],
				['id' => 'c5', 'employeeId' => 'e1', 'firstSickDay' => ''],
				['id' => 'c6', 'employeeId' => 'e1', 'firstSickDay' => '2026-06-08'],
			],
			'interviews' => [
				['id' => 'i1', 'employeeId' => 'e1', 'scheduledStart' => '2026-06-03T10:00:00', 'scheduledEnd' => '2026-06-03T11:00:00'],
				['id' => 'i2', 'interviewerId' => 'e2', 'participantId' => 'e1', 'start' => '2026-06-05T09:00:00'],
				['id' => 'i3', 'participantId' => 'e2', 'scheduledStart' => '2026-06-09T09:00:00'],
				['id' => 'i4', 'employeeId' => 'e1'],
				['id' => 'i5', 'employeeId' => 'x9', 'scheduledStart' => '2026-06-03T10:00:00'],
			],
			'secondments' => [
				['id' => 'd1', 'employeeId' => 'e1', 'status' => 'actief', 'startDate' => '2026-05-01', 'endDate' => '2026-06-04', 'receivingOrganisation' => 'Gemeente', 'hoursPerWeek' => 16],
				['id' => 'd2', 'employeeId' => 'e2', 'status' => 'beeindigd', 'startDate' => '2026-05-01'],
				['id' => 'd3', 'employeeId' => 'e2', 'status' => 'actief', 'startDate' => '2026-06-05', 'receivingOrganisation' => 'Provincie'],
				['id' => 'd4', 'employeeId' => 'e1', 'status' => 'actief', 'startDate' => '2026-06-10'],
			],
			'bookings' => [
				['id' => 'b1', 'employeeId' => 'e1', 'resourceId' => 'r1', 'start' => '2026-06-02T09:00:00', 'end' => '2026-06-02T10:00:00', 'purpose' => 'Overleg', 'domainObjectType' => 'Case', 'domainObjectRef' => 'z-1'],
				['id' => 'b2', 'resourceId' => 'r1', 'start' => '2026-06-07T23:00:00', 'end' => '2026-06-08T01:00:00'],
				['id' => 'b3', 'employeeId' => 'e2', 'resourceId' => ' r2 ', 'start' => '2026-06-01T08:00:00', 'end' => '2026-06-01T09:00:00', 'domainObjectType' => ' '],
				['id' => 'b4', 'employeeId' => 'e1', 'resourceId' => 'r1', 'start' => '2026-05-31T08:00:00', 'end' => '2026-05-31T09:00:00'],
				['id' => 'b5', 'employeeId' => 'e1', 'resourceId' => 'r1', 'start' => ' ', 'end' => '2026-06-02T10:00:00'],
				['id' => 'b6', 'employeeId' => 'e1', 'resourceId' => 'r2', 'start' => '2026-06-08T08:00:00', 'end' => '2026-06-08T09:00:00'],
			],
			'subscriptions' => [
				['id' => 'sub1', 'employeeId' => 'e1', 'busyPeriods' => [
					['start' => '2026-06-04T13:00:00', 'end' => '2026-06-04T14:00:00', 'summary' => 'never copied'],
					['start' => '2026-06-09T13:00:00', 'end' => '2026-06-09T14:00:00'],
					['start' => '2026-05-31T23:00:00', 'end' => '2026-06-01T01:00:00'],
					['start' => ''],
					'not a period',
				]],
				['id' => 'sub2', 'employeeId' => 'e2', 'busyPeriods' => 'none'],
				['id' => 'sub3', 'employeeId' => 'e2', 'busyPeriods' => [['start' => '2026-06-06T10:00:00', 'end' => '2026-06-06T11:00:00']]],
				['employeeId' => 'x9', 'busyPeriods' => [['start' => '2026-06-06T10:00:00', 'end' => '2026-06-06T11:00:00']]],
			],
		];

	}//end sources()

}//end class
