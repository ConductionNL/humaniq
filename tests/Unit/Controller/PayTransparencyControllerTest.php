<?php

/**
 * PayTransparencyController test
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Humaniq\Controller\PayTransparencyController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\DepartmentFiguresService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\PayTransparencyService;
use OCA\Humaniq\Service\Percentile;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may read the report, which administration and year it covers, and the CSV.
 */
class PayTransparencyControllerTest extends TestCase {

	/**
	 * Build the controller for a caller with the given administration role.
	 *
	 * @param string $role The role in ADM-001.
	 *
	 * @return PayTransparencyController
	 */
	private function controller(string $role): PayTransparencyController {
		$rows = ['Employee' => [], 'EmploymentContract' => [], 'Payslip' => [], 'Normfunctie' => [['id' => 'nf-1', 'payCategory' => 'Uitvoerend']]];
		foreach (['w1' => 'woman', 'w2' => 'woman', 'm1' => 'man', 'm2' => 'man', 'x1' => 'woman'] as $id => $gender) {
			$administration = $id === 'x1' ? 'ADM-002' : 'ADM-001';
			$rows['Employee'][] = ['id' => $id, 'gender' => $gender, 'grossMonthlySalary' => 2000, 'administrationId' => $administration];
			$rows['EmploymentContract'][] = ['id' => 'c-' . $id, 'employeeId' => $id, 'normfunctieId' => 'nf-1', 'startDate' => '2020-01-01', 'administrationId' => $administration];
			$rows['Payslip'][] = ['id' => 'p-' . $id, 'employeeId' => $id, 'period' => '2026-05', 'grossPay' => ($gender === 'man' ? 2000 : 1800), 'hoursWorked' => 100, 'administrationId' => $administration];
		}

		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationId')->willReturn('ADM-001');
		$administrations->method('getActiveAdministrationRole')->willReturn($role);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPayTransparencyMinimumGroup')->willReturn(2);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2027-02-01T10:00:00Z'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PayTransparencyController(
			$this->createMock(IRequest::class),
			$gateway,
			new PayTransparencyService(new Percentile()),
			new AnalyticsAccess($administrations, $this->createMock(DepartmentFiguresService::class)),
			$settings,
			$session,
			$time,
			$l10n
		);
	}//end controller()

	/**
	 * HR reads last year's report of its own administration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function testHrReadsLastYearOfItsOwnAdministration(): void {
		$response = $this->controller(role: 'hr')->report(year: 'last');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2026, $data['year']);
		$this->assertSame(4, $data['counted'], 'the ADM-002 employee is not in this administration');
		$this->assertSame(10.0, $data['overall']['meanGap']);
		$this->assertSame('Uitvoerend', $data['categories'][0]['category']);
		$this->assertSame('10.0%', $data['categories'][0]['meanGapText']);
	}//end testHrReadsLastYearOfItsOwnAdministration()

	/**
	 * An accountant may read it too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	public function testAnAccountantMayReadIt(): void {
		$this->assertSame(Http::STATUS_OK, $this->controller(role: 'accountant')->report(year: '2026')->getStatus());
	}//end testAnAccountantMayReadIt()

	/**
	 * A manager or employee is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	public function testAnyoneElseIsRefused(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(role: 'manager')->report()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(role: 'employee')->export()->getStatus());
	}//end testAnyoneElseIsRefused()

	/**
	 * The export is a CSV with the overall row and one row per category.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function testTheExportIsACsv(): void {
		$response = $this->controller(role: 'hr')->export(year: '2026');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$lines = explode("\n", trim($response->render()));
		$this->assertCount(3, $lines);
		$this->assertStringContainsString('"All employees","2","2","","10.0","10.0"', $lines[1]);
		$this->assertStringStartsWith('"Uitvoerend"', $lines[2]);
	}//end testTheExportIsACsv()

}//end class
