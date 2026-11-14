<?php

/**
 * Unit tests for IptoExportService.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\IptoExportService;
use PHPUnit\Framework\TestCase;

/**
 * The staff count rows come from the teaching lines of the confirmed plans of the reference week's school year.
 *
 * @covers \OCA\Humaniq\Service\IptoExportService
 * @uses   \OCA\Humaniq\Service\DeploymentPlanService
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-the-ipto-staff-count-file-shall-be-produced-from-the-confirmed-plans-req-edu-003
 */
class IptoExportServiceTest extends TestCase {

	/**
	 * The plans of one school.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function plans(): array {
		return [
			[
				'id'         => 'p-1',
				'employeeId' => 'e-1',
				'schoolYear' => '2026-2027',
				'status'     => 'vastgesteld',
				'lines'      => [
					['category' => 'lesgevend', 'subject' => 'Wiskunde', 'educationLevel' => 'havo', 'hoursPerWeek' => 12, 'weeks' => 40],
					['category' => 'lesgevend', 'subject' => 'Wiskunde', 'educationLevel' => 'havo', 'hoursPerWeek' => 2.5, 'weeks' => 20],
					['category' => 'lesgevend', 'subject' => 'Natuurkunde', 'educationLevel' => 'vwo', 'hoursPerWeek' => 6, 'weeks' => 40],
					['category' => 'lesgebonden', 'hours' => 500],
					['category' => 'lesgevend', 'subject' => 'Biologie', 'educationLevel' => 'vwo', 'hours' => 40],
				],
			],
			[
				'id'         => 'p-2',
				'employeeId' => 'e-2',
				'schoolYear' => '2026-2027',
				'status'     => 'concept',
				'lines'      => [['category' => 'lesgevend', 'subject' => 'Engels', 'educationLevel' => 'vmbo', 'hoursPerWeek' => 20, 'weeks' => 40]],
			],
			[
				'id'         => 'p-3',
				'employeeId' => 'e-3',
				'schoolYear' => '2025-2026',
				'status'     => 'vastgesteld',
				'lines'      => [['category' => 'lesgevend', 'subject' => 'Duits', 'educationLevel' => 'havo', 'hoursPerWeek' => 10, 'weeks' => 40]],
			],
		];
	}//end plans()

	/**
	 * The November export: one row per teacher, subject and level, the weekly hours summed; the BRIN number and the week are named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-the-november-export
	 */
	public function testExportsOneRowPerTeacherSubjectAndLevel(): void {
		$employees = [
			'e-1' => ['firstName' => 'Sam', 'lastName' => 'Jansen', 'employeeNumber' => 'P-001'],
			'e-2' => ['firstName' => 'Kim', 'lastName' => 'de Vries'],
		];

		$export = (new IptoExportService())->export(['id' => 'adm-1', 'brinNummer' => '12AB'], self::plans(), $employees, '2026-W46');

		$this->assertSame('12AB', $export['brinNummer']);
		$this->assertSame('adm-1', $export['administrationId']);
		$this->assertSame('2026-W46', $export['referenceWeek']);
		$this->assertSame('2026-2027', $export['schoolYear']);
		$this->assertNull($export['specificationVersion']);
		$this->assertSame(
			[
				['employeeId' => 'e-1', 'employeeNumber' => 'P-001', 'name' => 'Sam Jansen', 'subject' => 'Natuurkunde', 'educationLevel' => 'vwo', 'hoursPerWeek' => 6.0],
				['employeeId' => 'e-1', 'employeeNumber' => 'P-001', 'name' => 'Sam Jansen', 'subject' => 'Wiskunde', 'educationLevel' => 'havo', 'hoursPerWeek' => 14.5],
			],
			$export['rows']
		);
	}//end testExportsOneRowPerTeacherSubjectAndLevel()

	/**
	 * A draft plan's hours are not in the file and the teacher is listed as not confirmed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-draft-plan-is-left-out
	 */
	public function testADraftPlanIsLeftOutAndListed(): void {
		$export = (new IptoExportService())->export(['id' => 'adm-1', 'brinNummer' => '12AB'], self::plans(), ['e-2' => ['firstName' => 'Kim', 'lastName' => 'de Vries']], '2026-W46');

		$this->assertNotContains('e-2', array_column($export['rows'], 'employeeId'));
		$this->assertSame([['employeeId' => 'e-2', 'name' => 'Kim de Vries', 'planId' => 'p-2', 'status' => 'concept']], $export['notConfirmed']);
	}//end testADraftPlanIsLeftOutAndListed()

	/**
	 * A reference week in July belongs to the school year that started the August before.
	 *
	 * @return void
	 */
	public function testAJulyWeekReadsThePreviousSchoolYear(): void {
		$export = (new IptoExportService())->export(['id' => 'adm-1'], self::plans(), [], '2026-W28');

		$this->assertSame('2025-2026', $export['schoolYear']);
		$this->assertSame(['Duits'], array_column($export['rows'], 'subject'));
		$this->assertSame([], $export['notConfirmed']);
	}//end testAJulyWeekReadsThePreviousSchoolYear()

	/**
	 * Malformed reference weeks are refused.
	 *
	 * @return void
	 */
	public function testAMalformedWeekIsRefused(): void {
		$service = new IptoExportService();

		$this->assertNull($service->export([], [], [], '2026-46'));
		$this->assertNull(IptoExportService::weekStart('2026-W00'));
		$this->assertNull(IptoExportService::weekStart('2026-W54'));
		$this->assertNull(IptoExportService::weekStart('2025-W53'));
		$this->assertSame('2026-11-09', IptoExportService::weekStart('2026-W46')?->format('Y-m-d'));
		$this->assertSame('2026-12-28', IptoExportService::weekStart('2026-W53')?->format('Y-m-d'));
	}//end testAMalformedWeekIsRefused()
}//end class
