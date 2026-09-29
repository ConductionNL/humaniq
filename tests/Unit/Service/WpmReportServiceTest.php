<?php

/**
 * WpmReportServiceTest
 *
 * The yearly mobility (WPM) figures of expenses-travel-calculation, compiled
 * over the fake register from claims, arrangements and employees, with the
 * saved report validated against the register's own WpmReport schema.
 *
 * @category Tests
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
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Business and commuting kilometres per mode and fuel, the threshold, and
 * the trips without a mode.
 */
class WpmReportServiceTest extends TestCase {

	/**
	 * The register double.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The service under test.
	 *
	 * @var WpmReportService
	 */
	private WpmReportService $service;

	/**
	 * Set up the fake register with one administration's travel.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$this->service = new WpmReportService(gateway: $gateway, calculator: new TravelAllowanceCalculator());

		$this->store->seed('Employee', 'emp-1', ['administrationId' => 'ADM-001', 'startDate' => '2020-01-01']);
		$this->store->seed('Employee', 'emp-2', ['administrationId' => 'ADM-001', 'startDate' => '2024-01-01', 'endDate' => '2025-06-30']);
		$this->store->seed('Employee', 'emp-3', ['administrationId' => 'ADM-002', 'startDate' => '2020-01-01']);
	}//end setUp()

	/**
	 * A business claim.
	 *
	 * @param string      $uuid   The id.
	 * @param float       $km     The distance.
	 * @param string      $status The state.
	 * @param string|null $mode   The transport mode.
	 * @param string      $date   The expense date.
	 *
	 * @return void
	 */
	private function claim(string $uuid, float $km, string $status, ?string $mode='car', string $date='2026-06-10'): void {
		$claim = ['employeeId' => 'emp-1', 'title' => 'Trip ' . $uuid, 'category' => 'travel', 'travelType' => 'business', 'distanceKm' => $km, 'status' => $status, 'expenseDate' => $date, 'administrationId' => 'ADM-001'];
		if ($mode !== null) {
			$claim['transportMode'] = $mode;
			$claim['fuelType'] = 'gasoline';
		}

		$this->store->seed('Expense', $uuid, $claim);
	}//end claim()

	/**
	 * Scenario: an HR adviser compiles the 2026 report. 150 business km and
	 * 6163.2 commuting km, both car and gasoline; claims not yet approved,
	 * of another year or of another administration are left out.
	 *
	 * @return void
	 */
	public function testTheReportSumsBusinessAndCommutingKilometresPerModeAndFuel(): void {
		$this->claim('c-1', 100, 'approved');
		$this->claim('c-2', 50, 'reimbursed');
		$this->claim('c-draft', 999, 'submitted');
		$this->claim('c-2025', 999, 'approved', 'car', '2025-12-31');
		$this->store->seed('CommuteArrangement', 'a-1', ['employeeId' => 'emp-1', 'distanceKmOneWay' => 18, 'daysPerWeek' => 4, 'transportMode' => 'car', 'fuelType' => 'gasoline', 'startDate' => '2025-09-01', 'status' => 'approved', 'administrationId' => 'ADM-001']);
		$this->store->seed('CommuteArrangement', 'a-draft', ['employeeId' => 'emp-1', 'distanceKmOneWay' => 99, 'daysPerWeek' => 5, 'transportMode' => 'car', 'startDate' => '2026-01-01', 'status' => 'submitted', 'administrationId' => 'ADM-001']);
		$this->store->seed('Expense', 'other-adm', ['employeeId' => 'emp-3', 'title' => 'x', 'category' => 'travel', 'travelType' => 'business', 'distanceKm' => 999, 'status' => 'approved', 'expenseDate' => '2026-03-01', 'administrationId' => 'ADM-002', 'transportMode' => 'car']);

		$report = $this->service->compile(administrationId: 'ADM-001', year: 2026, userId: 'hr');

		self::assertSame([['transportMode' => 'car', 'fuelType' => 'gasoline', 'km' => 150.0]], $report['businessKm']);
		self::assertSame([['transportMode' => 'car', 'fuelType' => 'gasoline', 'km' => 6163.2]], $report['commuteKm']);
		self::assertSame(150.0, $report['totalBusinessKm']);
		self::assertSame(6163.2, $report['totalCommuteKm']);
		self::assertSame(1, $report['employeeCount']);
		self::assertFalse($report['meetsThreshold']);
		self::assertSame([], $report['tripsWithoutMode']);

		$saved = $this->store->state->objects['WpmReport'];
		self::assertCount(1, $saved);
		$payload = array_values($saved)[0];
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('WpmReport', $payload), (string)json_encode($payload));
	}//end testTheReportSumsBusinessAndCommutingKilometresPerModeAndFuel()

	/**
	 * An arrangement active for six months of the year contributes half a
	 * year of commuting kilometres; compiling again updates the one report.
	 *
	 * @return void
	 */
	public function testAnArrangementActiveForSixMonthsCountsHalfAYear(): void {
		$this->store->seed('CommuteArrangement', 'a-half', ['employeeId' => 'emp-1', 'distanceKmOneWay' => 18, 'daysPerWeek' => 4, 'transportMode' => 'bicycle', 'startDate' => '2026-07-01', 'status' => 'approved', 'administrationId' => 'ADM-001']);

		$this->service->compile(administrationId: 'ADM-001', year: 2026, userId: 'hr');
		$report = $this->service->compile(administrationId: 'ADM-001', year: 2026, userId: 'hr');

		self::assertSame([['transportMode' => 'bicycle', 'fuelType' => null, 'km' => 3081.6]], $report['commuteKm']);
		self::assertCount(1, $this->store->state->objects['WpmReport'], 'Compiling twice keeps one report per administration and year.');
	}//end testAnArrangementActiveForSixMonthsCountsHalfAYear()

	/**
	 * Scenario: a trip without a mode is listed, not dropped.
	 *
	 * @return void
	 */
	public function testATripWithoutAModeIsListedAndNotCounted(): void {
		$this->claim('c-1', 150, 'approved');
		$this->claim('c-nomode', 40, 'approved', null);

		$report = $this->service->compile(administrationId: 'ADM-001', year: 2026, userId: 'hr');

		self::assertSame(150.0, $report['totalBusinessKm']);
		self::assertSame([['schema' => 'Expense', 'id' => 'c-nomode', 'title' => 'Trip c-nomode', 'km' => 40.0]], $report['tripsWithoutMode']);
	}//end testATripWithoutAModeIsListedAndNotCounted()

	/**
	 * With 100 or more people employed in the year the duty applies.
	 *
	 * @return void
	 */
	public function testTheThresholdIsOneHundredEmployeesInTheYear(): void {
		for ($i = 0; $i < 99; $i++) {
			$this->store->seed('Employee', 'bulk-' . $i, ['administrationId' => 'ADM-001', 'startDate' => '2026-02-01']);
		}

		$report = $this->service->compile(administrationId: 'ADM-001', year: 2026, userId: 'hr');

		self::assertSame(100, $report['employeeCount']);
		self::assertTrue($report['meetsThreshold']);
	}//end testTheThresholdIsOneHundredEmployeesInTheYear()

}//end class
