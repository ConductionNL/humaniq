<?php

/**
 * The handoff to an outside payroll bureau: mutations as differences from
 * the previous handoff, and the intake of the bureau's payslips.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SalaryBureauExchangeService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Design D2 (compile) and D4 (intake), over the real gateway.
 */
class SalaryBureauExchangeServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var SalaryBureauExchangeService
	 */
	private SalaryBureauExchangeService $service;

	/**
	 * {@inheritDoc}
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
		$this->service = new SalaryBureauExchangeService(gateway: $gateway, logger: new NullLogger());

		$this->store->seed('hrAdministration', 'adm-6', ['administrationId' => 'ADM-006', 'name' => 'Stichting Buitenbureau', 'payrollProcessing' => 'external-bureau']);
		$this->store->seed('Employee', 'emp-a', ['firstName' => 'Anna', 'lastName' => 'Smit', 'administrationId' => 'ADM-006', 'startDate' => '2025-01-01', 'grossMonthlySalary' => 3800.00, 'iban' => 'NL91ABNA0417164300', 'taxTableColor' => 'wit', 'loonheffingskortingToegepast' => true, 'nextcloudUserId' => 'anna']);
		$this->store->seed('Employee', 'emp-b', ['firstName' => 'Bram', 'lastName' => 'Kok', 'administrationId' => 'ADM-006', 'startDate' => '2025-01-01', 'grossMonthlySalary' => 3100.00, 'iban' => 'NL20INGB0001234567', 'taxTableColor' => 'wit', 'loonheffingskortingToegepast' => true]);
		$this->store->seed('Employee', 'emp-other', ['firstName' => 'Olga', 'lastName' => 'Elders', 'administrationId' => 'ADM-001', 'startDate' => '2025-01-01', 'grossMonthlySalary' => 2000.00]);
	}//end setUp()

	/**
	 * The first handoff sends every employee of the administration as a
	 * starter, and nobody from another administration; the handoff and its
	 * mutations fit their real schemas.
	 *
	 * @return void
	 */
	public function testAFirstHandoffSendsEveryoneAsAStarter(): void {
		$outcome = $this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');

		self::assertSame(2, $outcome['mutationCount']);
		$mutations = $this->rowsOf('PayrollHandoffMutation');
		self::assertSame(['start', 'start'], array_column($mutations, 'kind'));
		self::assertEqualsCanonicalizing(['emp-a', 'emp-b'], array_column($mutations, 'employeeId'));

		$handoff = $this->rowsOf('PayrollHandoff')[0];
		self::assertSame(['ADM-006', '2026-04', 'concept', 'hr-1', 2], [$handoff['administrationId'], $handoff['period'], $handoff['status'], $handoff['compiledBy'], $handoff['mutationCount']]);
		unset($handoff['id']);
		self::assertSame([], RegisterSchemaValidator::errors('PayrollHandoff', $handoff));
		foreach ($mutations as $mutation) {
			unset($mutation['id']);
			$mutation['handoffId'] = '5b1d3f4e-0000-4000-8000-000000000001';
			$mutation['employeeId'] = '5b1d3f4e-0000-4000-8000-000000000002';
			self::assertSame([], RegisterSchemaValidator::errors('PayrollHandoffMutation', $mutation));
		}
	}//end testAFirstHandoffSendsEveryoneAsAStarter()

	/**
	 * After a sent handoff, an applied raise becomes one salary mutation
	 * from 3800.00 to 3876.00 linked to the raise; nothing else is sent.
	 *
	 * @return void
	 */
	public function testARaiseBecomesOneMutation(): void {
		$this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');
		$this->markSent('2026-04');
		$this->store->seed('Employee', 'emp-a', array_merge($this->store->find('emp-a', schema: 'Employee')->getObject(), ['grossMonthlySalary' => 3876.00]));
		$this->store->seed('CompAdjustment', 'raise-1', ['employeeId' => 'emp-a', 'status' => 'applied', 'appliedAt' => '2026-05-01T08:00:00Z', 'currentSalary' => 3800.00, 'proposedSalary' => 3876.00]);

		$outcome = $this->service->compile(administrationId: 'ADM-006', period: '2026-05', userId: 'hr-1');

		self::assertSame(1, $outcome['mutationCount']);
		$may = array_values(array_filter($this->rowsOf('PayrollHandoffMutation'), static fn (array $m): bool => $m['handoffId'] === $outcome['handoffId']));
		self::assertCount(1, $may);
		self::assertSame(['salary', 'emp-a', 'CompAdjustment', 'raise-1'], [$may[0]['kind'], $may[0]['employeeId'], $may[0]['sourceSchema'], $may[0]['sourceId']]);
		self::assertSame(['old' => 3800.0, 'new' => 3876.0], $may[0]['fields']['grossMonthlySalary']);
	}//end testARaiseBecomesOneMutation()

	/**
	 * A bank account changed and changed back before compiling is not sent;
	 * compiling a concept handoff again replaces its mutations.
	 *
	 * @return void
	 */
	public function testARevertedEditIsNotSentAndARecompileReplaces(): void {
		$this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');
		$this->markSent('2026-04');
		$employee = $this->store->find('emp-b', schema: 'Employee')->getObject();
		$this->store->seed('Employee', 'emp-b', array_merge($employee, ['iban' => 'NL02RABO0123456789']));
		$this->store->seed('Employee', 'emp-b', $employee);

		$first = $this->service->compile(administrationId: 'ADM-006', period: '2026-05', userId: 'hr-1');
		self::assertSame(0, $first['mutationCount']);

		$this->store->seed('Employee', 'emp-b', array_merge($employee, ['iban' => 'NL02RABO0123456789']));
		$again = $this->service->compile(administrationId: 'ADM-006', period: '2026-05', userId: 'hr-1');
		self::assertSame($first['handoffId'], $again['handoffId']);
		self::assertSame(1, $again['mutationCount']);
		$may = array_values(array_filter($this->rowsOf('PayrollHandoffMutation'), static fn (array $m): bool => $m['handoffId'] === $again['handoffId']));
		self::assertSame(['bank-account'], array_column($may, 'kind'));
	}//end testARevertedEditIsNotSentAndARecompileReplaces()

	/**
	 * The engine's administrations have no handoff.
	 *
	 * @return void
	 */
	public function testAnEngineAdministrationIsRefused(): void {
		$outcome = $this->service->compile(administrationId: 'ADM-001', period: '2026-05', userId: 'hr-1');

		self::assertSame('refused-engine', $outcome['status']);
		self::assertSame([], $this->rowsOf('PayrollHandoff'));
	}//end testAnEngineAdministrationIsRefused()

	/**
	 * A missing payslip and one for an employee outside the administration
	 * are blocking findings; a returned payslip is stamped with the
	 * employee's account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function testTheIntakeListsMissingAndUnknownEmployees(): void {
		$handoff = $this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');
		$this->store->seed('Payslip', 'slip-a', ['employeeId' => 'emp-a', 'period' => '2026-04', 'grossPay' => 3800.0, 'nettoPay' => 2800.0, 'payrollHandoffId' => $handoff['handoffId']]);
		$this->store->seed('Payslip', 'slip-x', ['employeeId' => 'emp-other', 'period' => '2026-04', 'grossPay' => 2000.0, 'nettoPay' => 1600.0, 'payrollHandoffId' => $handoff['handoffId']]);

		$findings = $this->service->checkIntake($handoff['handoffId']);

		self::assertSame(2, $findings['blocking']);
		self::assertEqualsCanonicalizing(['missing-payslip', 'unknown-employee'], array_column($findings['findings'], 'kind'));
		self::assertSame('emp-b', array_values(array_filter($findings['findings'], static fn (array $f): bool => $f['kind'] === 'missing-payslip'))[0]['employeeId']);
		$slip = $this->store->find('slip-a', schema: 'Payslip')->getObject();
		self::assertSame(['anna', 'external-bureau'], [$slip['userId'], $slip['externalSource']]);
		self::assertSame(2, $this->store->find($handoff['handoffId'], schema: 'PayrollHandoff')->getObject()['blockingFindings']);
	}//end testTheIntakeListsMissingAndUnknownEmployees()

	/**
	 * Mark the handoff of a period as sent.
	 *
	 * @param string $period The period.
	 *
	 * @return void
	 */
	private function markSent(string $period): void {
		foreach ($this->rowsOf('PayrollHandoff') as $row) {
			if ($row['period'] === $period) {
				$this->store->seed('PayrollHandoff', $row['id'], array_merge($row, ['status' => 'verzonden', 'deliveryReference' => 'LOKET-1']));
			}
		}
	}//end markSent()

	/**
	 * Every row of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		$this->store->setSchema($schema);
		return $this->store->findAll();
	}//end rowsOf()

}//end class
