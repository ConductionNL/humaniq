<?php

/**
 * Golden test for the yearly WPM mobility report.
 *
 * Compiles the report for one administration and year over business claims
 * (counted and not, other years, no or zero distance, with and without mode
 * and fuel) and commuting arrangements (counted and not, active for part of
 * the year, without mode, with zero kilometres), and records the saved
 * payload with the compile time masked. Every case is compared with
 * tests/fixtures/golden/wpm-report.json. Set RECORD_GOLDEN=1 to rewrite
 * that file.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TravelAllowanceCalculator;
use OCA\Humaniq\Service\WpmReportService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the WPM report.
 *
 * No coverage metadata: the report runs through the register gateway and
 * the travel calculator.
 */
class WpmReportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/wpm-report.json';

	/**
	 * Every case, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $rows) {
			$report = $this->compileOver($rows);
			unset($report['compiledAt'], $report['id']);
			$actual[$name] = $report;
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($golden as $name => $expected) {
			$this->assertSame(json_encode($expected, $flags), json_encode($actual[$name], $flags), $name);
		}

	}//end testGolden()

	/**
	 * Compile the 2026 report for ADM-1 over the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 *
	 * @return array<string, mixed>
	 */
	private function compileOver(array $rows): array {
		$store = new FakeObjectStore();
		foreach ($rows as $schema => $list) {
			foreach ($list as $row) {
				$store->seed($schema, (string)$row['id'], $row);
			}
		}

		$store->seed('Employee', 'emp-1', ['administrationId' => 'ADM-1', 'startDate' => '2020-01-01']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store, 'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper()]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		return (new WpmReportService(gateway: $gateway, calculator: new TravelAllowanceCalculator()))->compile('ADM-1', 2026, 'hr-1');

	}//end compileOver()

	/**
	 * Rows per schema, by scenario.
	 *
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private static function scenarios(): array {
		$claim = static fn (array $f): array => ($f + ['administrationId' => 'ADM-1', 'category' => 'travel', 'travelType' => 'business', 'status' => 'reimbursed', 'expenseDate' => '2026-03-01', 'distanceKm' => 12.34, 'transportMode' => 'car', 'fuelType' => 'electric']);
		$arr = static fn (array $f): array => ($f + ['administrationId' => 'ADM-1', 'status' => 'approved', 'distanceKmOneWay' => 10, 'daysPerWeek' => 3, 'startDate' => '2025-01-01', 'transportMode' => 'bike']);
		return [
			'empty' => [],
			'mixed' => [
				'Expense' => [
					$claim(['id' => 'c1']),
					$claim(['id' => 'c2', 'distanceKm' => '7.66', 'status' => 'approved']),
					$claim(['id' => 'c3', 'fuelType' => '']),
					$claim(['id' => 'c4', 'transportMode' => '', 'title' => 'Zonder vervoer']),
					$claim(['id' => 'c5', 'expenseDate' => '2025-12-31']),
					$claim(['id' => 'c6', 'distanceKm' => 0]),
					$claim(['id' => 'c7', 'distanceKm' => 'ver']),
					$claim(['id' => 'c8', 'status' => 'draft']),
					$claim(['id' => 'c9', 'transportMode' => 'train', 'fuelType' => null, 'distanceKm' => 101.25]),
				],
				'CommuteArrangement' => [
					$arr(['id' => 'a1']),
					$arr(['id' => 'a2', 'startDate' => '2026-07-01']),
					$arr(['id' => 'a3', 'endDate' => '2026-03-15', 'transportMode' => 'car', 'fuelType' => 'diesel']),
					$arr(['id' => 'a4', 'transportMode' => '']),
					$arr(['id' => 'a5', 'status' => 'ended', 'endDate' => '2026-09-30']), $arr(['id' => 'a8', 'status' => 'draft']),
					$arr(['id' => 'a6', 'daysPerWeek' => 0]),
					$arr(['id' => 'a7', 'distanceKmOneWay' => 'ver']),
				],
			],
		];

	}//end scenarios()

}//end class
