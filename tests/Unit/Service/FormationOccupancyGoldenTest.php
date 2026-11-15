<?php

/**
 * Golden test for FormationOccupancyService::occupancy().
 *
 * Runs the count over fixed places, contracts, leave requests and sickness
 * cases: places keyed by id or uuid or neither, negative and missing budgets,
 * contracts starting or ending inside the window, unusable hours, a zero
 * full-time week, approved and pending leave of counted and other types,
 * short and long sickness with progression and recovery, a weekend-only and
 * a two-week window, and an overfilled place. Every result is compared with
 * tests/fixtures/golden/formation-occupancy.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\AbsencePeriod;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\FormationAbsenceSources;
use OCA\Humaniq\Service\FormationOccupancyService;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for formation occupancy.
 *
 * @covers \OCA\Humaniq\Service\FormationOccupancyService
 * @uses   \OCA\Humaniq\Service\FormationAbsence
 * @uses   \OCA\Humaniq\Service\FormationAbsenceSources
 * @uses   \OCA\Humaniq\Service\FormationWindow
 * @uses   \OCA\Humaniq\Service\AbsencePeriod
 * @uses   \OCA\Humaniq\Service\AbsenceProgression
 *
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */
class FormationOccupancyGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/formation-occupancy.json';

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
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = json_encode($this->tally(self::scenarios()[$case]), $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Run one scenario through the count.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function tally(array $s): array {
		$service = new FormationOccupancyService(new AbsenceProgression());
		return $service->occupancy(
			$s['places'],
			$s['contracts'] ?? [],
			new FormationAbsenceSources($s['leave'] ?? [], $s['sick'] ?? [], $s['types'] ?? ['parental'], $s['weeks'] ?? 6),
			new AbsencePeriod(new DateTimeImmutable($s['from'] ?? '2026-03-02'), new DateTimeImmutable($s['to'] ?? '2026-03-06'), $s['fullTime'] ?? 40.0)
		);

	}//end tally()

	/**
	 * A contract row.
	 *
	 * @param string $employeeId The employee.
	 * @param string $placeId The place.
	 * @param mixed $hours Hours per week.
	 * @param array<string, mixed> $extra Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function contract(string $employeeId, string $placeId, mixed $hours = 40, array $extra = []): array {
		return ($extra + ['employeeId' => $employeeId, 'formatieplaatsId' => $placeId, 'hoursPerWeek' => $hours, 'startDate' => '2025-01-01', 'endDate' => null]);

	}//end contract()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$p1 = ['id' => 'p1', 'title' => 'Plaats 1', 'budgetedFte' => 1.0];
		$p2 = ['uuid' => 'p2', 'title' => 'Plaats 2', 'budgetedFte' => 0.5];

		return [
			'no places' => ['places' => []],
			'empty place' => ['places' => [$p1]],
			'id, uuid and neither' => ['places' => [$p1, $p2, ['title' => 'Zonder id', 'budgetedFte' => 2]], 'contracts' => [self::contract('e1', 'p1'), self::contract('e2', 'p2', 20), self::contract('e3', '', 40)]],
			'negative and missing budget' => ['places' => [['id' => 'p1', 'budgetedFte' => -1], ['id' => 'p3']], 'contracts' => [self::contract('e1', 'p1', 12), self::contract('e3', 'p3', 8)]],
			'overfilled' => ['places' => [$p1, $p2], 'contracts' => [self::contract('e1', 'p1'), self::contract('e2', 'p1', 16), self::contract('e3', 'p2', 32)]],
			'contract starts and ends in window' => ['places' => [$p1], 'contracts' => [self::contract('e1', 'p1', 40, ['startDate' => '2026-03-04']), self::contract('e2', 'p1', 40, ['endDate' => '2026-03-03']), self::contract('e3', 'p1', 40, ['startDate' => null])]],
			'unusable hours' => ['places' => [$p1], 'contracts' => [self::contract('e1', 'p1', 'veel'), self::contract('e2', 'p1', -4), self::contract('e3', 'p1', null)]],
			'zero full-time week' => ['places' => [$p1], 'fullTime' => 0.0, 'contracts' => [self::contract('e1', 'p1')]],
			'36 hour week' => ['places' => [$p1, $p2], 'fullTime' => 36.0, 'contracts' => [self::contract('e1', 'p1', 36), self::contract('e2', 'p2', 18)]],
			'leave' => [
				'places' => [$p1],
				'contracts' => [self::contract('e1', 'p1'), self::contract('e2', 'p1', 20), self::contract('e3', 'p1', 20), self::contract('e4', 'p1', 8)],
				'types' => ['parental', 'zwangerschap'],
				'leave' => [
					['employeeId' => 'e1', 'status' => 'approved', 'leaveType' => 'parental', 'startDate' => '2026-03-03', 'endDate' => '2026-03-04'],
					['employeeId' => 'e2', 'status' => 'pending', 'leaveType' => 'parental', 'startDate' => '2026-03-02', 'endDate' => '2026-03-06'],
					['employeeId' => 'e3', 'status' => 'approved', 'leaveType' => 'vakantie', 'startDate' => '2026-03-02', 'endDate' => '2026-03-06'],
					['employeeId' => 'e4', 'status' => 'approved', 'leaveType' => 'zwangerschap', 'startDate' => '2026-03-05'],
					['employeeId' => '', 'status' => 'approved', 'leaveType' => 'parental', 'startDate' => '2026-03-02'],
				],
			],
			'sickness' => [
				'places' => [$p1, $p2],
				'contracts' => [self::contract('e1', 'p1'), self::contract('e2', 'p1', 20), self::contract('e3', 'p2', 20), self::contract('e4', 'p2', 20)],
				'weeks' => 4,
				'sick' => [
					['employeeId' => 'e1', 'firstSickDay' => '2026-01-05', 'status' => 'gemeld'],
					['employeeId' => 'e2', 'firstSickDay' => '2026-02-23', 'status' => 'gemeld'],
					['employeeId' => 'e3', 'firstSickDay' => '2026-01-05', 'recoveredDate' => '2026-03-03', 'status' => 'hersteld'],
					['employeeId' => 'e4', 'firstSickDay' => '2026-01-05', 'status' => 'gemeld', 'absenceProgression' => [['effectiveFrom' => '2026-01-05', 'absencePercentage' => 100], ['effectiveFrom' => '2026-03-04', 'absencePercentage' => 30]]],
					['employeeId' => '', 'firstSickDay' => '2026-01-05'],
				],
			],
			'sick and on leave' => [
				'places' => [$p1],
				'contracts' => [self::contract('e1', 'p1')],
				'weeks' => 0,
				'leave' => [['employeeId' => 'e1', 'status' => 'approved', 'leaveType' => 'parental', 'startDate' => '2026-03-02', 'endDate' => '2026-03-02']],
				'sick' => [['employeeId' => 'e1', 'firstSickDay' => '2026-03-01', 'status' => 'gemeld', 'absenceProgression' => [['effectiveFrom' => '2026-03-01', 'absencePercentage' => 50]]]],
			],
			'weekend only' => ['places' => [$p1], 'from' => '2026-03-07', 'to' => '2026-03-08', 'contracts' => [self::contract('e1', 'p1', 40, ['startDate' => '2026-03-08']), self::contract('e2', 'p1', 20)]],
			'two weeks' => ['places' => [$p1], 'from' => '2026-03-02', 'to' => '2026-03-15', 'contracts' => [self::contract('e1', 'p1', 40, ['endDate' => '2026-03-09']), self::contract('e2', 'p1', 20, ['startDate' => '2026-03-11'])]],
		];

	}//end scenarios()

}//end class
