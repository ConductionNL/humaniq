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
 * Design D2: the period items a bureau needs travel as their own mutations, once, over the real gateway.
 */
class PayrollHandoffPeriodItemsTest extends TestCase {

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
	 * Seed the April items of Anna: approved hours, a payroll-route claim,
	 * an allowance, a sold leave day, a sickness case and a garnishment;
	 * plus items that must not travel (a draft timesheet, March hours, a
	 * directly reimbursed claim, a concept garnishment).
	 *
	 * @return void
	 */
	private function seedAprilItems(): void {
		$this->store->seed('Timesheet', 'ts-apr', ['employeeId' => 'emp-a', 'period' => '2026-04', 'hours' => 152.0, 'overtimeHours' => 4.0, 'status' => 'approved']);
		$this->store->seed('Timesheet', 'ts-mar', ['employeeId' => 'emp-a', 'period' => '2026-03', 'hours' => 160.0, 'status' => 'approved']);
		$this->store->seed('Timesheet', 'ts-draft', ['employeeId' => 'emp-b', 'period' => '2026-04', 'hours' => 40.0, 'status' => 'draft']);
		$this->store->seed('Expense', 'exp-pay', ['employeeId' => 'emp-a', 'title' => 'Treinreis Utrecht', 'status' => 'approved', 'reimbursementRoute' => 'payroll', 'amount' => 42.5, 'taxFreeAmount' => 42.5, 'category' => 'travel', 'expenseDate' => '2026-04-08']);
		$this->store->seed('Expense', 'exp-direct', ['employeeId' => 'emp-a', 'title' => 'Lunch', 'status' => 'approved', 'reimbursementRoute' => 'direct', 'amount' => 12.0, 'expenseDate' => '2026-04-09']);
		$this->store->seed('RecurringAllowance', 'alw-home', ['employeeId' => 'emp-a', 'kind' => 'thuiswerk', 'amountPerDay' => 2.4, 'daysPerMonth' => 8, 'taxTreatment' => 'gericht-vrijgesteld', 'startDate' => '2026-01-01', 'status' => 'active']);
		$this->store->seed('LeaveTransaction', 'ltx-sell', ['employeeId' => 'emp-a', 'transactionType' => 'sell', 'year' => 2026, 'leaveType' => 'holiday', 'hours' => 8.0, 'hourlyRate' => 23.4, 'status' => 'approved']);
		$this->store->seed('SickLeaveCase', 'sick-a', ['employeeId' => 'emp-a', 'firstSickDay' => '2026-04-10', 'status' => 'gemeld', 'loondoorbetalingPercentage' => 100]);
		$this->store->seed('Loonbeslag', 'beslag-a', ['employeeId' => 'emp-a', 'creditor' => 'CJIB', 'dossierRef' => 'CJIB-2026-1', 'totalClaim' => 900.0, 'orderedAmount' => 150.0, 'beslagvrijeVoet' => 1650.0, 'status' => 'actief', 'effectiveFrom' => '2026-04-01']);
		$this->store->seed('Loonbeslag', 'beslag-concept', ['employeeId' => 'emp-b', 'creditor' => 'Gemeente', 'dossierRef' => 'G-1', 'totalClaim' => 100.0, 'orderedAmount' => 50.0, 'beslagvrijeVoet' => 1650.0, 'status' => 'concept', 'effectiveFrom' => '2026-04-01']);
	}//end seedAprilItems()

	/**
	 * The first handoff sends the starters and, as their own mutations, each
	 * period item linked to the object it came from; items outside the
	 * period or not ready stay home. Every mutation fits its real schema.
	 *
	 * @return void
	 */
	public function testThePeriodItemsTravelAsTheirOwnMutations(): void {
		$this->seedAprilItems();

		$outcome = $this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');

		$mutations = $this->rowsOf('PayrollHandoffMutation');
		self::assertSame(8, $outcome['mutationCount']);
		$bySource = [];
		foreach ($mutations as $mutation) {
			$bySource[$mutation['kind'] . ':' . $mutation['sourceId']] = $mutation;
		}

		self::assertEqualsCanonicalizing(
			['start:emp-a', 'start:emp-b', 'hours:ts-apr', 'claim:exp-pay', 'allowance:alw-home', 'leave-transaction:ltx-sell', 'sickness:sick-a', 'garnishment:beslag-a'],
			array_keys($bySource)
		);
		self::assertSame(['Timesheet', '2026-04-01', ['old' => null, 'new' => 152.0]], [$bySource['hours:ts-apr']['sourceSchema'], $bySource['hours:ts-apr']['effectiveDate'], $bySource['hours:ts-apr']['fields']['hours']]);
		self::assertSame(['Expense', '2026-04-08'], [$bySource['claim:exp-pay']['sourceSchema'], $bySource['claim:exp-pay']['effectiveDate']]);
		self::assertSame('2026-04-10', $bySource['sickness:sick-a']['effectiveDate']);
		self::assertSame('2026-04-01', $bySource['garnishment:beslag-a']['effectiveDate']);

		foreach ($mutations as $mutation) {
			unset($mutation['id']);
			$mutation['handoffId'] = '5b1d3f4e-0000-4000-8000-000000000001';
			$mutation['employeeId'] = '5b1d3f4e-0000-4000-8000-000000000002';
			self::assertSame([], RegisterSchemaValidator::errors('PayrollHandoffMutation', $mutation), $mutation['kind']);
		}
	}//end testThePeriodItemsTravelAsTheirOwnMutations()

	/**
	 * After April went out, May sends May's hours and the recovery of the
	 * sickness case, and nothing that April already sent.
	 *
	 * @return void
	 */
	public function testASentItemIsNotSentAgainButItsChangeIs(): void {
		$this->seedAprilItems();
		$this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');
		$this->markSent('2026-04');
		$this->store->seed('Timesheet', 'ts-may', ['employeeId' => 'emp-a', 'period' => '2026-05', 'hours' => 144.0, 'status' => 'approved']);
		$this->store->seed('SickLeaveCase', 'sick-a', ['employeeId' => 'emp-a', 'firstSickDay' => '2026-04-10', 'recoveredDate' => '2026-05-06', 'status' => 'hersteld', 'loondoorbetalingPercentage' => 100]);

		$outcome = $this->service->compile(administrationId: 'ADM-006', period: '2026-05', userId: 'hr-1');

		$may = array_values(array_filter($this->rowsOf('PayrollHandoffMutation'), static fn (array $m): bool => $m['handoffId'] === $outcome['handoffId']));
		self::assertSame(2, $outcome['mutationCount']);
		self::assertEqualsCanonicalizing(['hours:ts-may', 'sickness:sick-a'], array_map(static fn (array $m): string => $m['kind'] . ':' . $m['sourceId'], $may));
		$sick = array_values(array_filter($may, static fn (array $m): bool => $m['kind'] === 'sickness'))[0];
		self::assertSame(['old' => null, 'new' => '2026-05-06'], $sick['fields']['recoveredDate']);
		self::assertSame('2026-05-06', $sick['effectiveDate']);
		self::assertArrayNotHasKey('firstSickDay', $sick['fields']);
	}//end testASentItemIsNotSentAgainButItsChangeIs()

	/**
	 * A garnishment that was sent and then settled travels once more as
	 * its end; one that never went out and is already settled does not.
	 *
	 * @return void
	 */
	public function testASettledGarnishmentIsSentOnlyWhenItWentOut(): void {
		$this->seedAprilItems();
		$this->service->compile(administrationId: 'ADM-006', period: '2026-04', userId: 'hr-1');
		$this->markSent('2026-04');
		$this->store->seed('Loonbeslag', 'beslag-a', ['employeeId' => 'emp-a', 'creditor' => 'CJIB', 'dossierRef' => 'CJIB-2026-1', 'totalClaim' => 900.0, 'orderedAmount' => 150.0, 'beslagvrijeVoet' => 1650.0, 'status' => 'voldaan', 'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-05-31']);
		$this->store->seed('Loonbeslag', 'beslag-old', ['employeeId' => 'emp-b', 'creditor' => 'Gemeente', 'dossierRef' => 'G-0', 'totalClaim' => 100.0, 'orderedAmount' => 50.0, 'beslagvrijeVoet' => 1650.0, 'status' => 'voldaan', 'effectiveFrom' => '2025-01-01', 'effectiveTo' => '2025-03-31']);

		$outcome = $this->service->compile(administrationId: 'ADM-006', period: '2026-05', userId: 'hr-1');

		$may = array_values(array_filter($this->rowsOf('PayrollHandoffMutation'), static fn (array $m): bool => $m['handoffId'] === $outcome['handoffId']));
		self::assertSame(['garnishment:beslag-a'], array_map(static fn (array $m): string => $m['kind'] . ':' . $m['sourceId'], $may));
		self::assertSame(['old' => 'actief', 'new' => 'voldaan'], $may[0]['fields']['status']);
		self::assertSame('2026-05-31', $may[0]['effectiveDate']);
	}//end testASettledGarnishmentIsSentOnlyWhenItWentOut()

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
