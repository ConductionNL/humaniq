<?php

/**
 * Unit tests for DepartmentFiguresService: units compared side by side, the
 * units a manager leads, and the small-unit rule.
 *
 * Drives the real AnalyticsService, UnitMembership, DepartmentFigures and
 * AbsenceRateService over a fake OpenRegister ObjectService (a fake
 * collaborator, not a fake of the logic under test), the AnalyticsServiceTest
 * precedent.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AbsenceRateService;
use OCA\Humaniq\Service\AnalyticsCalculators;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\DepartmentFigureCalculators;
use OCA\Humaniq\Service\DepartmentFigures;
use OCA\Humaniq\Service\DepartmentFiguresService;
use OCA\Humaniq\Service\Percentile;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\UnitMembership;
use OCA\Humaniq\Service\WorkforceReportSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DepartmentFiguresService.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class DepartmentFiguresServiceTest extends TestCase {

	/**
	 * The fixture organisation: Gemeente with Burgerzaken (managed by the
	 * user `lead`) and Belastingen (managed by `other`).
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function organisation(): array {
		$admin = ['administrationId' => 'ADM-001'];
		return [
			'OrgUnit' => [
				(['id' => 'gemeente', 'name' => 'Gemeente', 'parentUnitId' => null, 'managerId' => 'emp-director'] + $admin),
				(['id' => 'burgerzaken', 'name' => 'Burgerzaken', 'parentUnitId' => 'gemeente', 'managerId' => 'emp-lead'] + $admin),
				(['id' => 'balie', 'name' => 'Balie', 'parentUnitId' => 'burgerzaken', 'managerId' => null] + $admin),
				(['id' => 'belastingen', 'name' => 'Belastingen', 'parentUnitId' => 'gemeente', 'managerId' => 'emp-other'] + $admin),
			],
			'Employee' => [
				(['id' => 'emp-lead', 'nextcloudUserId' => 'lead'] + $admin),
				(['id' => 'emp-other', 'nextcloudUserId' => 'other'] + $admin),
			],
			'OrgAssignment' => [
				(['employeeId' => 'a', 'orgUnitId' => 'burgerzaken', 'startDate' => '2025-01-01'] + $admin),
				(['employeeId' => 'b', 'orgUnitId' => 'balie', 'startDate' => '2025-01-01'] + $admin),
				(['employeeId' => 'c', 'orgUnitId' => 'belastingen', 'startDate' => '2025-01-01'] + $admin),
			],
		];
	}//end organisation()

	/**
	 * "A controller splits the wage cost": June cost 400,000, split over the
	 * two units with the remainder for people placed nowhere, and the parts
	 * add up to the whole. With a single top unit, the comparison defaults
	 * to the units under it.
	 *
	 * @return void
	 */
	public function testTheUnitsWageCostsAddUpToTheAdministrationTotal(): void {
		$admin = ['administrationId' => 'ADM-001'];
		$rows = $this->organisation() + [
			'PayrollRun' => [
				(['id' => 'run-june', 'period' => '2026-06', 'status' => 'posted', 'totalGross' => 320000.0, 'totalEmployerCharges' => 80000.0] + $admin),
			],
			'Payslip' => [
				(['employeeId' => 'a', 'payrollRunId' => 'run-june', 'grossPay' => 100000.0] + $admin),
				(['employeeId' => 'b', 'payrollRunId' => 'run-june', 'grossPay' => 60000.0] + $admin),
				(['employeeId' => 'c', 'payrollRunId' => 'run-june', 'grossPay' => 96000.0] + $admin),
				(['employeeId' => 'd', 'payrollRunId' => 'run-june', 'grossPay' => 64000.0] + $admin),
			],
		];
		$service = $this->buildService($rows);

		$result = $service->compare('ADM-001', '2026-06', null);

		$this->assertSame('2026-06-01', $result['from']);
		$this->assertSame('2026-06-30', $result['to']);
		$this->assertSame('gemeente', $result['parentUnitId']);
		$this->assertSame(['Burgerzaken', 'Belastingen'], array_column($result['units'], 'name'));

		$children = $service->compare('ADM-001', '2026-06', 'gemeente');
		$byName = array_column($children['units'], 'wageCost', 'name');
		$this->assertSame(['Burgerzaken' => 200000.0, 'Belastingen' => 120000.0], $byName);
		$this->assertSame(80000.0, $children['notPlaced']['wageCost']);
		$this->assertSame(400000.0, $children['total']['wageCost']);
		$this->assertSame(400000.0, (array_sum($byName) + $children['notPlaced']['wageCost']));
	}//end testTheUnitsWageCostsAddUpToTheAdministrationTotal()

	/**
	 * The whole comparison when one person is split over two units, a month
	 * has no payroll run and a unit has no cost: one month, and the trailing
	 * quarter.
	 *
	 * @return void
	 */
	public function testTheWholeComparisonWithASplitPlacementAndAMissingMonth(): void {
		$admin = ['administrationId' => 'ADM-001'];
		$thisMonth = new \DateTimeImmutable('first day of this month');
		$runs = [(['id' => 'run-june', 'period' => '2026-06', 'status' => 'posted', 'totalGross' => 300000.0, 'totalEmployerCharges' => 60000.0] + $admin)];
		$payslips = [
			(['employeeId' => 'a', 'payrollRunId' => 'run-june', 'grossPay' => 100000.0] + $admin),
			(['employeeId' => 'c', 'payrollRunId' => 'run-june', 'grossPay' => 50000.0] + $admin),
		];
		foreach ([0, 2] as $back) {
			$month = $thisMonth->modify('-' . $back . ' months')->format('Y-m');
			$runs[] = (['id' => 'run-' . $month, 'period' => $month, 'status' => 'posted', 'totalGross' => 9000.0, 'totalEmployerCharges' => 1000.0] + $admin);
			$payslips[] = (['employeeId' => 'a', 'payrollRunId' => 'run-' . $month, 'grossPay' => 3000.0] + $admin);
			$payslips[] = (['employeeId' => 'b', 'payrollRunId' => 'run-' . $month, 'grossPay' => 2000.0] + $admin);
		}

		$rows = $this->organisation();
		$rows['OrgAssignment'][] = (['employeeId' => 'a', 'orgUnitId' => 'belastingen', 'startDate' => '2025-01-01'] + $admin);
		$rows['OrgUnit'][] = (['id' => 'leeg', 'name' => 'Leeg', 'parentUnitId' => 'gemeente', 'managerId' => null] + $admin);
		$rows += ['PayrollRun' => $runs, 'Payslip' => $payslips];
		$service = $this->buildService($rows, 1);
		$got = [];
		foreach ([['2026-06', null], ['2026-06', 'gemeente'], ['2026-06', 'burgerzaken'], ['2026-05', 'gemeente'], ['quarter', 'gemeente']] as [$period, $parent]) {
			$result = $service->compare('ADM-001', $period, $parent);
			unset($result['from'], $result['to']);
			$got[$period . ':' . (string)$parent] = $result;
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/department-figures-compare.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
	}//end testTheWholeComparisonWithASplitPlacementAndAMissingMonth()

	/**
	 * A named period is the trailing months ending in the current one.
	 *
	 * @return void
	 */
	public function testAQuarterIsTheTrailingThreeMonths(): void {
		$result = $this->buildService($this->organisation())->compare('ADM-001', 'quarter', null);

		$thisMonth = new \DateTimeImmutable('first day of this month');
		$this->assertSame($thisMonth->modify('-2 months')->format('Y-m-d'), $result['from']);
		$this->assertSame($thisMonth->modify('last day of this month')->format('Y-m-d'), $result['to']);
	}//end testAQuarterIsTheTrailingThreeMonths()

	/**
	 * "HR compares two teams": each unit carries its own absence rate and
	 * frequency over the window.
	 *
	 * @return void
	 */
	public function testUnitsCarryTheirOwnRateAndFrequency(): void {
		$admin = ['administrationId' => 'ADM-001'];
		$rows = $this->organisation() + [
			'EmploymentContract' => [
				(['employeeId' => 'a', 'hoursPerWeek' => 40.0, 'startDate' => '2020-01-01'] + $admin),
				(['employeeId' => 'b', 'hoursPerWeek' => 40.0, 'startDate' => '2020-01-01'] + $admin),
				(['employeeId' => 'c', 'hoursPerWeek' => 40.0, 'startDate' => '2020-01-01'] + $admin),
			],
			'SickLeaveCase' => [
				(['employeeId' => 'a', 'firstSickDay' => '2026-06-01', 'recoveredDate' => '2026-06-30', 'status' => 'hersteld'] + $admin),
			],
		];
		$service = $this->buildService($rows);

		$result = $service->compare('ADM-001', '2026-06', 'gemeente');
		$byName = [];
		foreach ($result['units'] as $row) {
			$byName[$row['name']] = $row;
		}

		$this->assertSame(2, $byName['Burgerzaken']['members']);
		$this->assertGreaterThan(0.0, $byName['Burgerzaken']['absenceRate']);
		$this->assertSame(0.0, $byName['Belastingen']['absenceRate']);
		$this->assertSame(6.08, $byName['Burgerzaken']['absenceFrequency']);
		$this->assertSame(0.0, $byName['Belastingen']['absenceFrequency']);
	}//end testUnitsCarryTheirOwnRateAndFrequency()

	/**
	 * A manager leads their unit and its children, and not a sibling unit.
	 *
	 * @return void
	 */
	public function testAManagerManagesTheirUnitAndItsChildrenOnly(): void {
		$service = $this->buildService($this->organisation());

		$this->assertTrue($service->managesUnit('lead', 'ADM-001', 'burgerzaken'));
		$this->assertTrue($service->managesUnit('lead', 'ADM-001', 'balie'));
		$this->assertFalse($service->managesUnit('lead', 'ADM-001', 'belastingen'));
		$this->assertFalse($service->managesUnit('lead', 'ADM-001', 'gemeente'));
		$this->assertFalse($service->managesUnit('nobody', 'ADM-001', 'burgerzaken'));
	}//end testAManagerManagesTheirUnitAndItsChildrenOnly()

	/**
	 * "A team leader reads their department": their units only, as totals,
	 * and a unit under the small-unit threshold answers null with a reason.
	 *
	 * @return void
	 */
	public function testAManagerSeesTheirUnitsAndASmallUnitIsWithheld(): void {
		$service = $this->buildService($this->organisation(), 5);

		$result = $service->managedUnits('lead', 'ADM-001', '2026-06');

		$this->assertCount(1, $result['units']);
		$row = $result['units'][0];
		$this->assertSame('burgerzaken', $row['id']);
		$this->assertSame(2, $row['members']);
		$this->assertNull($row['absenceRate']);
		$this->assertNull($row['wageCost']);
		$this->assertSame('unit-too-small', $row['suppressed']);
		$this->assertArrayNotHasKey('employees', $row);

		$open = $this->buildService($this->organisation(), 2)->managedUnits('lead', 'ADM-001', '2026-06');
		$this->assertArrayNotHasKey('suppressed', $open['units'][0]);
	}//end testAManagerSeesTheirUnitsAndASmallUnitIsWithheld()

	/**
	 * Open vacancies of a unit are the published ones on its formation
	 * places, children included.
	 *
	 * @return void
	 */
	public function testOpenVacanciesAreThePublishedOnesOnTheUnitsPlaces(): void {
		$admin = ['administrationId' => 'ADM-001'];
		$rows = $this->organisation() + [
			'Formatieplaats' => [
				(['id' => 'fp-1', 'orgUnitId' => 'balie'] + $admin),
				(['id' => 'fp-2', 'orgUnitId' => 'belastingen'] + $admin),
			],
			'Vacancy' => [
				(['formatieplaatsId' => 'fp-1', 'status' => 'gepubliceerd'] + $admin),
				(['formatieplaatsId' => 'fp-1', 'status' => 'gesloten'] + $admin),
				(['formatieplaatsId' => 'fp-2', 'status' => 'gepubliceerd'] + $admin),
			],
		];

		$row = $this->buildService($rows)->unitFigures('ADM-001', 'burgerzaken', '2026-06', 0);

		$this->assertSame(1, $row['openVacancies']);
		$this->assertSame('Burgerzaken', $row['name']);
	}//end testOpenVacanciesAreThePublishedOnesOnTheUnitsPlaces()

	/**
	 * Build the service over canned rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $rowsBySchema Canned rows per schema.
	 * @param int                                       $minimum      The small-unit threshold.
	 *
	 * @return DepartmentFiguresService
	 */
	private function buildService(array $rowsBySchema, int $minimum=5): DepartmentFiguresService {
		$objectService = new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $rowsBySchema Canned rows.
			 */
			public function __construct(private readonly array $rowsBySchema) {
			}

			/**
			 * @param string $register Ignored.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options): array {
				return ($this->rowsBySchema[$this->schema] ?? []);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$analytics = new AnalyticsService($container, $settings, $this->createMock(LoggerInterface::class), new AnalyticsCalculators(new AbsenceRateService(), new Percentile()));

		return new DepartmentFiguresService($analytics, new UnitMembership(), new WorkforceReportSettings(AppConfigValues::answer($this->createMock(IAppConfig::class), ['department_figures_minimum_members' => $minimum])), new DepartmentFigureCalculators(new DepartmentFigures(), new AbsenceRateService()));
	}//end buildService()

}//end class
