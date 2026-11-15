<?php

/**
 * Golden test for LearningPeopleFeed::people().
 *
 * Runs the feed over fixed Employee, OrgUnit and OrgAssignment rows: active,
 * future and ended employees, current, ended and overlapping placements,
 * a placement on a missing unit, managers on the own and a parent unit, the
 * employee who manages their own unit, blank and padded names, ids only
 * under `@self`, and the modified-since filter with older, newer, absent
 * and unreadable stamps. Every row is compared with
 * tests/fixtures/golden/learning-people-feed.json. Set RECORD_GOLDEN=1 to
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\LearningPeopleFeed;
use OCA\Humaniq\Service\OrgResolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the learning people feed.
 *
 * @covers \OCA\Humaniq\Service\LearningPeopleFeed
 * @uses   \OCA\Humaniq\Service\PeopleFeedIndexes
 * @uses   \OCA\Humaniq\Service\PeopleFeedSubject
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */
class LearningPeopleFeedGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/learning-people-feed.json';

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
		$actual = json_encode($this->free(self::scenarios()[$case]), $flags);
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
	 * Run one scenario through the feed.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function free(array $s): array {
		$rows = self::rows();
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));

		return (new LearningPeopleFeed($gateway, new OrgResolutionService()))->people($s['today'] ?? '2026-09-30', $s['since'] ?? null);

	}//end free()

	/**
	 * The register rows every scenario reads.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function rows(): array {
		$old = ['@self' => ['updated' => '2026-01-10T09:00:00+00:00']];
		$new = ['@self' => ['updated' => '2026-09-28T16:00:00+00:00']];

		return [
			'Employee' => [
				(['id' => 'mgr-top', 'firstName' => 'Tess', 'lastName' => 'Top', 'nextcloudUserId' => 't.top', 'startDate' => '2019-01-01'] + $old),
				(['id' => 'mgr-fin', 'firstName' => 'Fleur', 'lastName' => 'Financien', 'nextcloudUserId' => 'f.fin', 'startDate' => '2020-01-01'] + $old),
				(['id' => 'mover', 'firstName' => ' Anna ', 'lastName' => 'Visser', 'nextcloudUserId' => 'a.visser', 'startDate' => '2023-02-01', 'bsn' => '111222333'] + $old),
				(['id' => 'stayer', 'firstName' => '', 'lastName' => 'Bakker', 'startDate' => '2022-05-01'] + $new),
				(['id' => 'leaver', 'firstName' => 'Lies', 'lastName' => 'Vertrek', 'startDate' => '2021-01-01', 'endDate' => '2026-09-22'] + $old),
				(['id' => 'future', 'firstName' => 'Nina', 'lastName' => 'Nieuw', 'startDate' => '2026-11-01'] + $old),
				['@self' => ['id' => 'selfonly', 'updated' => 'gisteren'], 'firstName' => 'Sam', 'lastName' => 'Zelf', 'startDate' => '2024-01-01'],
				['id' => 'nostamp', 'firstName' => 'Olaf', 'lastName' => 'Ongedateerd', 'startDate' => '2024-01-01'],
				['id' => ' ', 'firstName' => 'Leeg'],
			],
			'OrgUnit' => [
				(['id' => 'unit-top', 'name' => 'Directie', 'managerId' => 'mgr-top'] + $old),
				(['id' => 'unit-fin', 'name' => ' Finance ', 'managerId' => 'mgr-fin', 'parentId' => 'unit-top'] + $old),
				(['id' => 'unit-hr', 'name' => '', 'parentId' => 'unit-top'] + $new),
			],
			'OrgAssignment' => [
				(['employeeId' => 'mover', 'orgUnitId' => 'unit-fin', 'role' => 'Adviseur', 'startDate' => '2023-02-01', 'endDate' => '2026-09-28'] + $new),
				(['employeeId' => 'mover', 'orgUnitId' => 'unit-hr', 'role' => ' HR-adviseur ', 'startDate' => '2026-09-29'] + $new),
				(['employeeId' => 'stayer', 'orgUnitId' => 'unit-fin', 'role' => '', 'startDate' => '2022-05-01'] + $old),
				(['employeeId' => 'stayer', 'orgUnitId' => 'unit-top', 'role' => 'Tijdelijk', 'startDate' => '2021-05-01'] + $old),
				(['employeeId' => 'mgr-fin', 'orgUnitId' => 'unit-fin', 'role' => 'Manager', 'startDate' => '2020-01-01'] + $old),
				(['employeeId' => 'leaver', 'orgUnitId' => 'unit-hr', 'startDate' => '2021-01-01', 'endDate' => '2026-09-22'] + $old),
				(['employeeId' => 'selfonly', 'orgUnitId' => 'unit-gone', 'role' => 'Zwerver', 'startDate' => '2024-01-01'] + $old),
				(['employeeId' => 'future', 'orgUnitId' => 'unit-fin', 'startDate' => '2026-11-01'] + $old),
			],
		];

	}//end rows()

	/**
	 * Every scenario, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		return [
			'everyone today' => [],
			'before the move' => ['today' => '2026-09-28'],
			'after the leaver' => ['today' => '2026-09-21'],
			'later' => ['today' => '2026-11-15'],
			'changed since' => ['since' => '2026-09-01T00:00:00+00:00'],
			'changed since later' => ['since' => '2026-09-29T00:00:00+00:00'],
			'changed exactly at since' => ['since' => '2026-09-28T16:00:00+00:00'],
			'unreadable since' => ['since' => 'ooit'],
			'blank since' => ['since' => ' '],
		];

	}//end scenarios()

}//end class
