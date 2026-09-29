<?php

/**
 * Unit tests for FormationBudgetController (reporting-personnel-budget-and-scenarios D3, D4).
 *
 * The real AnalyticsAccess decides over a mocked AdministrationService; the
 * real PersonnelBudgetService computes over rows a gateway double answers.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\FormationBudgetController;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\CaoScaleLookup;
use OCA\Humaniq\Service\DepartmentFiguresService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\PersonnelBudgetService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the budget endpoints.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
class FormationBudgetControllerTest extends TestCase {

	/**
	 * HR and an accountant read the budget; a manager is refused.
	 *
	 * @return void
	 */
	public function testOnlyHrAndAccountantsReadTheBudget(): void {
		self::assertSame(200, $this->controller('hr')->budget('sc-1')->getStatus());
		self::assertSame(200, $this->controller('accountant')->budget('sc-1')->getStatus());
		$refused = $this->controller('manager')->budget('sc-1');
		self::assertSame(403, $refused->getStatus());
		self::assertArrayNotHasKey('lines', $refused->getData());
		self::assertSame(403, $this->controller('manager')->compare('sc-1', 'sc-1')->getStatus());
	}//end testOnlyHrAndAccountantsReadTheBudget()

	/**
	 * The budget names the cost centre and the occupant's salary basis.
	 *
	 * @return void
	 */
	public function testTheBudgetShowsTheCostCentre(): void {
		$data = $this->controller('accountant')->budget('sc-1')->getData();

		self::assertSame(2027, $data['year']);
		self::assertEqualsWithDelta(12 * 3000 * 1.08, $data['byCostCenter']['4100']['total'], 0.01);
	}//end testTheBudgetShowsTheCostCentre()

	/**
	 * A scenario of another administration is not found.
	 *
	 * @return void
	 */
	public function testAScenarioOfAnotherAdministrationIsNotFound(): void {
		self::assertSame(404, $this->controller('hr')->budget('sc-other')->getStatus());
	}//end testAScenarioOfAnotherAdministrationIsNotFound()

	/**
	 * The comparison answers per unit.
	 *
	 * @return void
	 */
	public function testTheComparisonAnswersPerUnit(): void {
		$data = $this->controller('hr')->compare('sc-1', 'sc-1')->getData();

		self::assertArrayHasKey('unit-1', $data['units']);
		self::assertSame('Team Burgerzaken', $data['unitRows'][0]['name']);
		self::assertEqualsWithDelta(0.0, $data['unitRows'][0]['diffACost'], 0.001);
	}//end testTheComparisonAnswersPerUnit()

	/**
	 * The controller for a caller with a role in ADM-001.
	 *
	 * @param string $role The caller's role.
	 *
	 * @return FormationBudgetController
	 */
	private function controller(string $role): FormationBudgetController {
		$rows = [
			'FormationScenario' => [
				['id' => 'sc-1', 'name' => 'Begroting 2027', 'year' => 2027, 'caoRaisePercentage' => 0, 'status' => 'concept', 'administrationId' => 'ADM-001'],
				['id' => 'sc-other', 'name' => 'Elders', 'year' => 2027, 'status' => 'concept', 'administrationId' => 'ADM-002'],
			],
			'Formatieplaats' => [['id' => 'place-1', 'orgUnitId' => 'unit-1', 'title' => 'Medewerker', 'budgetedFte' => 1.0, 'administrationId' => 'ADM-001']],
			'EmploymentContract' => [['id' => 'c-1', 'employeeId' => 'emp-1', 'formatieplaatsId' => 'place-1', 'hoursPerWeek' => 40, 'startDate' => '2024-01-01', 'administrationId' => 'ADM-001']],
			'Employee' => [['id' => 'emp-1', 'grossMonthlySalary' => 3000, 'administrationId' => 'ADM-001']],
			'OrgUnit' => [['id' => 'unit-1', 'name' => 'Team Burgerzaken', 'costCenter' => '4100', 'administrationId' => 'ADM-001']],
		];
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationId')->willReturn('ADM-001');
		$administrations->method('getActiveAdministrationRole')->willReturn($role);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FormationBudgetController(
			$this->createMock(IRequest::class),
			$gateway,
			new PersonnelBudgetService(new AbsenceProgression(), new CaoScaleLookup()),
			new AnalyticsAccess($administrations, $this->createMock(DepartmentFiguresService::class)),
			$session
		);
	}//end controller()

}//end class
