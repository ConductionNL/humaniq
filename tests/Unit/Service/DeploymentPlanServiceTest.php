<?php

/**
 * Unit tests for DeploymentPlanService.
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

use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\Humaniq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The norm follows the contracts in the school year; a plan's totals and overload.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
 */
class DeploymentPlanServiceTest extends TestCase {

	/**
	 * The service with a verified CAO PO norm of 1,659 hours and no norm for any other CAO.
	 *
	 * @return DeploymentPlanService
	 */
	private function service(): DeploymentPlanService {
		return new DeploymentPlanService(annualNorm: static fn (string $caoId): ?float => ($caoId === 'cao-onderwijs-po' ? 1659.0 : null));
	}//end service()

	/**
	 * A contract.
	 *
	 * @param float       $hours The hours per week.
	 * @param string      $start The start date.
	 * @param string|null $end   The end date.
	 * @param string      $cao   The CAO id.
	 *
	 * @return array<string, mixed>
	 */
	private static function contract(float $hours, string $start, ?string $end = null, string $cao = 'cao-onderwijs-po'): array {
		return ['hoursPerWeek' => $hours, 'startDate' => $start, 'endDate' => $end, 'cao' => $cao];
	}//end contract()

	/**
	 * A full-time contract over the whole school year gives the whole annual norm.
	 *
	 * @return void
	 */
	public function testAFullTimeContract(): void {
		$this->assertSame(['hours' => 1659.0, 'source' => 'cao'], $this->service()->normFor([self::contract(40.0, '2019-08-01')], '2026-2027'));
	}//end testAFullTimeContract()

	/**
	 * A 0.6 contract (24 hours) over the whole year gives 0.6 of the norm.
	 *
	 * @return void
	 */
	public function testAPointSixContract(): void {
		$this->assertSame(['hours' => 995.4, 'source' => 'cao'], $this->service()->normFor([self::contract(24.0, '2020-01-01', '2030-12-31')], '2026-2027'));
	}//end testAPointSixContract()

	/**
	 * A 24-hour contract starting 1 February: 1,659 times 0.6 times the 181 of 365 days from 1 February.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-part-time-contract-halfway-through-the-year
	 */
	public function testAPartTimeContractHalfwayThroughTheYear(): void {
		$norm = $this->service()->normFor([self::contract(24.0, '2027-02-01')], '2026-2027');

		$this->assertSame(['hours' => round(1659 * 0.6 * 181 / 365, 2), 'source' => 'cao'], $norm);
		$this->assertSame(493.61, $norm['hours']);
	}//end testAPartTimeContractHalfwayThroughTheYear()

	/**
	 * A contract starting in January covers 212 of the 365 days; one ending at New Year the other 153; together the whole norm.
	 *
	 * @return void
	 */
	public function testAContractStartingInJanuary(): void {
		$this->assertSame(963.58, $this->service()->normFor([self::contract(40.0, '2027-01-01')], '2026-2027')['hours']);
		$this->assertSame(
			1659.0,
			$this->service()->normFor([self::contract(40.0, '2025-08-01', '2026-12-31'), self::contract(40.0, '2027-01-01T00:00:00+01:00')], '2026-2027')['hours']
		);
	}//end testAContractStartingInJanuary()

	/**
	 * A leap school year counts 366 days, and contracts outside the year count nothing.
	 *
	 * @return void
	 */
	public function testTheSchoolYearBounds(): void {
		$this->assertSame(round(1659 * 29 / 366, 2), $this->service()->normFor([self::contract(40.0, '2028-02-01', '2028-02-29')], '2027-2028')['hours']);
		$this->assertSame(1659.0, $this->service()->normFor([self::contract(40.0, '2024-01-01', '2026-07-31'), self::contract(40.0, '2026-08-01')], '2026-2027')['hours']);
		$this->assertNull($this->service()->normFor([self::contract(40.0, '2027-08-01')], '2026-2027'), 'no contract in the year');
		$this->assertNull($this->service()->normFor([], '2026-2027'));
		foreach (['2026', '2026-2028', '26-27', ''] as $malformed) {
			$this->assertNull($this->service()->normFor([self::contract(40.0, '2019-08-01')], $malformed), $malformed);
		}
	}//end testTheSchoolYearBounds()

	/**
	 * When any contract's CAO has no usable norm, or a contract has no hours, the norm is entered by hand (null).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-an-unverified-cao-figure-is-not-used
	 */
	public function testAnUnverifiedCaoFigureIsNotUsed(): void {
		$this->assertNull($this->service()->normFor([self::contract(40.0, '2019-08-01', null, 'cao-onderwijs-vo')], '2026-2027'));
		$this->assertNull($this->service()->normFor([self::contract(40.0, '2019-08-01', '2027-01-31'), self::contract(40.0, '2027-02-01', null, 'cao-onderwijs-vo')], '2026-2027'));
		$this->assertNull($this->service()->normFor([['startDate' => '2019-08-01', 'cao' => 'cao-onderwijs-po']], '2026-2027'));

		CaoRegistry::reset();
		$this->assertNull((new DeploymentPlanService())->normFor([self::contract(40.0, '2019-08-01')], '2026-2027'), 'the shipped CAO PO figure is unverified');
	}//end testAnUnverifiedCaoFigureIsNotUsed()

	/**
	 * Lines of 1,700 hours against a norm of 1,659: 41 hours overload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-school-leader-sees-an-overload
	 */
	public function testASchoolLeaderSeesAnOverload(): void {
		$totals = $this->service()->totals(
			[
				'normHours' => 1659,
				'lines'     => [
					['category' => 'lesgevend', 'subject' => 'Rekenen', 'educationLevel' => 'groep 6', 'hoursPerWeek' => 25, 'weeks' => 40, 'hours' => 1],
					['category' => 'lesgebonden', 'hours' => 450],
					['category' => 'schooltaak', 'hours' => 127],
					['category' => 'professionalisering', 'hours' => 83],
					['category' => 'duurzame-inzetbaarheid', 'hours' => 40],
				],
			]
		);

		$this->assertSame(1700.0, $totals['plannedHours']);
		$this->assertSame(41.0, $totals['differenceHours']);
		$this->assertTrue($totals['overload']);
		$this->assertSame(1000.0, $totals['lines'][0]['hours'], 'a teaching line counts its weekly hours times its weeks');
		$this->assertSame('Rekenen', $totals['lines'][0]['subject']);
	}//end testASchoolLeaderSeesAnOverload()

	/**
	 * Room under the norm is not an overload; without a norm there is no difference; malformed lines count nothing.
	 *
	 * @return void
	 */
	public function testRoomAndMissingNorm(): void {
		$room = $this->service()->totals(['normHours' => '995.4', 'lines' => [['category' => 'lesgevend', 'hoursPerWeek' => 15.5, 'weeks' => 40], ['category' => 'schooltaak']]]);
		$this->assertSame([620.0, -375.4, false], [$room['plannedHours'], $room['differenceHours'], $room['overload']]);
		$this->assertSame(0.0, $room['lines'][1]['hours']);

		$noNorm = $this->service()->totals(['lines' => ['not a line', ['category' => 'schooltaak', 'hours' => 10]]]);
		$this->assertSame([10.0, null, false], [$noNorm['plannedHours'], $noNorm['differenceHours'], $noNorm['overload']]);
		$this->assertCount(1, $noNorm['lines']);

		$this->assertSame(['lines' => [], 'plannedHours' => 0.0, 'differenceHours' => null, 'overload' => false], $this->service()->totals(['lines' => 'x']));
	}//end testRoomAndMissingNorm()
}//end class
