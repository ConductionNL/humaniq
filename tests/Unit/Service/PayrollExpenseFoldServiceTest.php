<?php

/**
 * Unit tests for PayrollExpenseFoldService.
 *
 * The gateway is a mock that answers loadAll() per schema and records every
 * save; the splitter and the write marker are the real classes. Rows are
 * shaped like the register's Expense, RecurringAllowance, CommuteArrangement
 * and WkrDeclaration fragments.
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\PayrollRunApprovedListener;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OvertimeCreditService;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Claims paid once, allowances split, WKR rows upserted, claims marked reimbursed.
 */
class PayrollExpenseFoldServiceTest extends TestCase {

	/**
	 * Every save the gateway received.
	 *
	 * @var array<int, array{payload: array<string, mixed>, schema: string, uuid: ?string, internal: bool}>
	 */
	private array $saves = [];

	/**
	 * A service over the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows keyed by schema.
	 *
	 * @return PayrollExpenseFoldService
	 */
	private function service(array $rows): PayrollExpenseFoldService {
		$marker = new InternalWriteMarker();
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null) use ($marker): object {
			$this->saves[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid, 'internal' => $marker->isInternal()];
			return new \stdClass();
		});

		return new PayrollExpenseFoldService($gateway, $marker);
	}//end service()

	/**
	 * The approved 27.40 train claim of the seed, payroll route.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function claim(array $overrides = []): array {
		return array_merge(
			['id' => 'exp-1', 'employeeId' => 'emp-1', 'title' => 'Treinkaartje', 'amount' => 27.40, 'status' => 'approved', 'approvedAt' => '2026-05-12T10:00:00Z', 'reimbursementRoute' => 'payroll'],
			$overrides
		);
	}//end claim()

	/**
	 * The verified 2026 norm.
	 *
	 * @return array{perDayCents: int, verified: bool}
	 */
	private function norm(): array {
		return ['perDayCents' => 245, 'verified' => true];
	}//end norm()

	/**
	 * An approved payroll-route claim approved inside the period is paid once
	 * on net, named, and not taxed.
	 *
	 * @return void
	 */
	public function testAnApprovedPayrollClaimIsFoldedIntoNet(): void {
		$service = $this->service(['Expense' => [$this->claim()]]);
		$inputs = $service->inputs(norm: $this->norm());

		$fold = $service->foldFor(inputs: $inputs, employeeId: 'emp-1', period: '2026-05', runId: 'run-5');

		$this->assertSame(2740, $fold['claimCents']);
		$this->assertSame(['exp-1'], $fold['claimIds']);
		$this->assertSame(0, $fold['taxedCents']);
		$fields = $service->payslipFields($fold);
		$this->assertSame(27.40, $fields['reimbursements']);
		$this->assertSame(['exp-1'], $fields['reimbursedExpenseIds']);
	}//end testAnApprovedPayrollClaimIsFoldedIntoNet()

	/**
	 * Direct-route, unapproved, later-approved, taxable, other-run and other
	 * employee claims are not selected; this run's own stamped claim is.
	 *
	 * @return void
	 */
	public function testOnlyTheRightClaimsAreSelected(): void {
		$service = $this->service([
			'Expense' => [
				$this->claim(['id' => 'direct', 'reimbursementRoute' => 'direct']),
				$this->claim(['id' => 'noroute', 'reimbursementRoute' => null]),
				$this->claim(['id' => 'submitted', 'status' => 'submitted']),
				$this->claim(['id' => 'reimbursed', 'status' => 'reimbursed']),
				$this->claim(['id' => 'june', 'approvedAt' => '2026-06-01T08:00:00Z']),
				$this->claim(['id' => 'taxable', 'taxableAmount' => 10.50]),
				$this->claim(['id' => 'otherrun', 'payrollRunId' => 'run-4']),
				$this->claim(['id' => 'otheremp', 'employeeId' => 'emp-2']),
				$this->claim(['id' => 'noamount', 'amount' => null]),
				$this->claim(['id' => 'mine', 'payrollRunId' => 'run-5']),
			],
		]);

		$fold = $service->foldFor(inputs: $service->inputs(norm: null), employeeId: 'emp-1', period: '2026-05', runId: 'run-5');

		$this->assertSame(['mine'], $fold['claimIds']);
	}//end testOnlyTheRightClaimsAreSelected()

	/**
	 * Without claims or allowances the payslip gains no field at all.
	 *
	 * @return void
	 */
	public function testNothingToFoldAddsNoPayslipField(): void {
		$service = $this->service([]);

		$fold = $service->foldFor(inputs: $service->inputs(norm: $this->norm()), employeeId: 'emp-1', period: '2026-05', runId: 'run-5');

		$this->assertSame([], $service->payslipFields($fold));
		$this->assertSame(0, $fold['claimCents'] + $fold['taxedCents'] + $fold['untaxedCents']);
	}//end testNothingToFoldAddsNoPayslipField()

	/**
	 * Allowances covering the period are split; an unpaid one is listed with
	 * its reason; untaxed parts produce a WKR row keyed on allowance and period.
	 *
	 * @return void
	 */
	public function testAllowancesAreSplitListedAndProduceWkrRows(): void {
		$service = $this->service([
			'RecurringAllowance' => [
				['id' => 'alw-home', 'employeeId' => 'emp-1', 'kind' => 'thuiswerk', 'amountPerDay' => 2.45, 'daysPerMonth' => 8, 'taxTreatment' => 'gericht-vrijgesteld', 'startDate' => '2026-01-01', 'status' => 'active'],
				['id' => 'alw-phone', 'employeeId' => 'emp-1', 'kind' => 'telefoon', 'amountPerMonth' => 20.00, 'taxTreatment' => 'belast', 'startDate' => '2026-01-01', 'status' => 'active'],
				['id' => 'alw-free', 'employeeId' => 'emp-1', 'kind' => 'other', 'amountPerMonth' => 50.00, 'taxTreatment' => 'vrije-ruimte', 'startDate' => '2026-01-01', 'status' => 'active'],
				['id' => 'alw-travel', 'employeeId' => 'emp-1', 'kind' => 'reiskosten', 'taxTreatment' => 'gericht-vrijgesteld', 'commuteArrangementId' => 'ca-gone', 'startDate' => '2026-01-01', 'status' => 'active'],
				['id' => 'alw-draft', 'employeeId' => 'emp-1', 'kind' => 'other', 'amountPerMonth' => 99.00, 'taxTreatment' => 'belast', 'startDate' => '2026-01-01', 'status' => 'draft'],
				['id' => 'alw-other', 'employeeId' => 'emp-2', 'kind' => 'other', 'amountPerMonth' => 99.00, 'taxTreatment' => 'belast', 'startDate' => '2026-01-01', 'status' => 'active'],
			],
		]);

		$fold = $service->foldFor(inputs: $service->inputs(norm: $this->norm()), employeeId: 'emp-1', period: '2026-05', runId: 'run-5');

		$this->assertSame(2000, $fold['taxedCents']);
		$this->assertSame(6960, $fold['untaxedCents']);
		$this->assertSame(['alw-home', 'alw-phone', 'alw-free', 'alw-travel'], array_column($fold['lines'], 'allowanceId'));
		$this->assertSame(['paid', 'paid', 'paid', 'arrangement-missing'], array_column($fold['lines'], 'status'));
		$this->assertSame(['allowance:alw-home:2026-05', 'allowance:alw-free:2026-05'], array_column($fold['wkr'], 'sourceReference'));
		$this->assertSame([19.60, 50.00], array_column($fold['wkr'], 'amount'));
		$this->assertSame(['gericht-vrijgesteld', 'vrije-ruimte'], array_column($fold['wkr'], 'wkrCategory'));
		$this->assertSame('2026-05-31', $fold['wkr'][0]['date']);
		$this->assertSame(2026, $fold['wkr'][0]['year']);

		$fields = $service->payslipFields($fold);
		$this->assertSame(20.00, $fields['allowancesTaxed']);
		$this->assertSame(69.60, $fields['allowancesUntaxed']);
		$this->assertSame(0.0, $fields['reimbursements']);
		$this->assertCount(4, $fields['allowanceLines']);
	}//end testAllowancesAreSplitListedAndProduceWkrRows()

	/**
	 * Paid claims are stamped with the run; a claim this run paid before but
	 * no longer pays is unstamped; an unchanged stamp is not rewritten.
	 *
	 * @return void
	 */
	public function testClaimsAreStampedAndUnstampedInternally(): void {
		$expenses = [
			$this->claim(['id' => 'exp-1']),
			$this->claim(['id' => 'exp-2', 'payrollRunId' => 'run-5', 'paidInPeriod' => '2026-05', 'status' => 'submitted']),
			$this->claim(['id' => 'exp-3', 'payrollRunId' => 'run-5', 'paidInPeriod' => '2026-05']),
			$this->claim(['id' => 'exp-4']),
		];
		$service = $this->service(['Expense' => $expenses]);

		$service->stampClaims(expenses: $expenses, paidIds: ['exp-1', 'exp-3'], runId: 'run-5', period: '2026-05');

		$this->assertSame(['exp-1', 'exp-2'], array_column($this->saves, 'uuid'));
		$this->assertSame('run-5', $this->saves[0]['payload']['payrollRunId']);
		$this->assertSame('2026-05', $this->saves[0]['payload']['paidInPeriod']);
		$this->assertArrayNotHasKey('id', $this->saves[0]['payload']);
		$this->assertNull($this->saves[1]['payload']['payrollRunId']);
		$this->assertTrue($this->saves[0]['internal']);
	}//end testClaimsAreStampedAndUnstampedInternally()

	/**
	 * A WKR row is created once and rewritten in place on recalculation.
	 *
	 * @return void
	 */
	public function testWkrRowsAreUpsertedOnTheirSourceReference(): void {
		$service = $this->service([
			'WkrDeclaration' => [
				['id' => 'wkr-existing', 'sourceReference' => 'allowance:alw-free:2026-05', 'amount' => 40.00, 'wkrCategory' => 'vrije-ruimte'],
			],
		]);
		$rows = [
			['sourceReference' => 'allowance:alw-free:2026-05', 'amount' => 50.00, 'wkrCategory' => 'vrije-ruimte', 'employeeId' => 'emp-1', 'date' => '2026-05-31', 'year' => 2026, 'description' => 'x'],
			['sourceReference' => 'allowance:alw-home:2026-05', 'amount' => 19.60, 'wkrCategory' => 'gericht-vrijgesteld', 'employeeId' => 'emp-1', 'date' => '2026-05-31', 'year' => 2026, 'description' => 'y'],
		];

		$service->writeWkr(inputs: $service->inputs(norm: null), rows: $rows, administrationId: 'ADM-001');

		$this->assertSame(['wkr-existing', null], array_column($this->saves, 'uuid'));
		$this->assertSame(50.00, $this->saves[0]['payload']['amount']);
		$this->assertSame('ADM-001', $this->saves[1]['payload']['administrationId']);
		$this->assertSame('WkrDeclaration', $this->saves[1]['schema']);
		$this->assertTrue($this->saves[1]['internal']);
	}//end testWkrRowsAreUpsertedOnTheirSourceReference()

	/**
	 * Approving the run marks its claims reimbursed once; claims of other runs
	 * and already reimbursed claims are left alone.
	 *
	 * @return void
	 */
	public function testApprovingTheRunMarksItsClaimsReimbursedOnce(): void {
		$service = $this->service([
			'Expense' => [
				$this->claim(['id' => 'exp-1', 'payrollRunId' => 'run-5']),
				$this->claim(['id' => 'exp-2', 'payrollRunId' => 'run-4']),
				$this->claim(['id' => 'exp-3', 'payrollRunId' => 'run-5', 'status' => 'reimbursed']),
			],
		]);

		$this->assertSame(1, $service->markReimbursed('run-5'));
		$this->assertSame('exp-1', $this->saves[0]['uuid']);
		$this->assertSame('reimbursed', $this->saves[0]['payload']['status']);
		$this->assertNotEmpty($this->saves[0]['payload']['reimbursedAt']);
		$this->assertTrue($this->saves[0]['internal']);
		$this->assertSame(0, $service->markReimbursed(''));
	}//end testApprovingTheRunMarksItsClaimsReimbursedOnce()

	/**
	 * The approval listener marks the run's claims on draft to approved only.
	 *
	 * @return void
	 */
	public function testTheApprovalListenerMarksTheRunsClaims(): void {
		$service = $this->service(['Expense' => [$this->claim(['id' => 'exp-1', 'payrollRunId' => 'run-5'])]]);
		$listener = new PayrollRunApprovedListener($this->createMock(OvertimeCreditService::class), new NullLogger(), $service);
		$run = static function (string $status): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid('run-5');
			$entity->setSchema('PayrollRun');
			$entity->setObject(['period' => '2026-05', 'status' => $status]);
			return $entity;
		};

		$listener->handle(new ObjectUpdatedEvent($run('approved'), $run('approved')));
		$this->assertSame([], $this->saves);

		$listener->handle(new ObjectUpdatedEvent($run('approved'), $run('draft')));
		$this->assertSame(['exp-1'], array_column($this->saves, 'uuid'));
	}//end testTheApprovalListenerMarksTheRunsClaims()

	/**
	 * The norm is read from the bundled 2026 tables as a verified leaf, and a
	 * table without the leaf yields no norm.
	 *
	 * @return void
	 */
	public function testTheNormIsReadFromTheTables(): void {
		$service = $this->service([]);

		$this->assertSame(['perDayCents' => 245, 'verified' => true], $service->normFrom(TaxTables::load('nl-2026')));
		$document = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Standards/tables/nl-2026.json'), true);
		$document['parameters']['wkr']['thuiswerkNormPerDag']['verified'] = false;
		$this->assertSame(['perDayCents' => 245, 'verified' => false], $service->normFrom(TaxTables::fromDocument($document, 'unverified')));

		unset($document['parameters']['wkr']['thuiswerkNormPerDag']);
		$this->assertNull($service->normFrom(TaxTables::fromDocument($document, 'without')));
	}//end testTheNormIsReadFromTheTables()

}//end class
