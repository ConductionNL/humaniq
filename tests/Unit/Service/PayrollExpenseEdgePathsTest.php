<?php

/**
 * Edge paths of the claims-and-allowances fold and its listeners.
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

use OCA\Humaniq\Listener\ExpenseRouteListener;
use OCA\Humaniq\Listener\PayrollRunApprovedListener;
use OCA\Humaniq\Listener\RecurringAllowanceStampListener;
use OCA\Humaniq\Service\AllowanceSplitter;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OvertimeCreditService;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the fold and the listeners do when their input is incomplete.
 */
class PayrollExpenseEdgePathsTest extends TestCase {

	/**
	 * An entity carrying the given data.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A register that cannot be read yields no rows, no fold and no marks;
	 * a malformed period folds nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterOrAMalformedPeriodFoldsNothing(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willThrowException(new \RuntimeException('no schema'));
		$gateway->expects($this->never())->method('save');
		$service = new PayrollExpenseFoldService($gateway, new InternalWriteMarker());

		$inputs = $service->inputs(norm: null);
		$this->assertSame([], $inputs['expenses']);
		$this->assertSame(0, $service->markReimbursed('run-5'));

		$rows = ['Expense' => [['id' => 'e', 'employeeId' => 'emp-1', 'amount' => 5, 'status' => 'approved', 'approvedAt' => '2026-01-01', 'reimbursementRoute' => 'payroll']], 'RecurringAllowance' => []];
		$reader = $this->createMock(HoursRegisterGateway::class);
		$reader->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$fold = (new PayrollExpenseFoldService($reader, new InternalWriteMarker()))->foldFor(inputs: ['expenses' => $rows['Expense'], 'allowances' => [], 'arrangementsById' => [], 'wkrByReference' => [], 'norm' => null], employeeId: 'emp-1', period: '2026-13', runId: 'r');
		$this->assertSame([], $fold['claimIds']);
	}//end testAnUnreadableRegisterOrAMalformedPeriodFoldsNothing()

	/**
	 * A travel allowance whose arrangement has no amount pays nothing; one
	 * with only a monthly allowance pays it untaxed; a taxed travel allowance
	 * with an amount is wage.
	 *
	 * @return void
	 */
	public function testTravelAllowanceEdges(): void {
		$splitter = new AllowanceSplitter();
		$travel = ['kind' => 'reiskosten', 'taxTreatment' => 'gericht-vrijgesteld', 'commuteArrangementId' => 'ca-1'];

		$this->assertSame('no-amount', $splitter->split(allowance: $travel, arrangement: ['id' => 'ca-1'], norm: null)['status']);
		$this->assertSame([0, 9000], array_values(array_intersect_key($splitter->split(allowance: $travel, arrangement: ['monthlyAllowance' => 90], norm: null), ['taxedCents' => 0, 'untaxedCents' => 0])));
		$this->assertSame(3000, $splitter->split(allowance: ['kind' => 'reiskosten', 'taxTreatment' => 'belast', 'amountPerMonth' => 30], arrangement: null, norm: null)['taxedCents']);
	}//end testTravelAllowanceEdges()

	/**
	 * A failing claim step is logged and does not stop the credit; a run
	 * that is not the approval edge, or another event, does nothing.
	 *
	 * @return void
	 */
	public function testTheApprovalListenerSurvivesAFailingClaimStep(): void {
		$expenses = $this->createMock(PayrollExpenseFoldService::class);
		$expenses->expects($this->once())->method('markReimbursed')->willThrowException(new \RuntimeException('down'));
		$credits = $this->createMock(OvertimeCreditService::class);
		$credits->expects($this->once())->method('creditForRun');
		$listener = new PayrollRunApprovedListener($credits, new NullLogger(), $expenses);

		$listener->handle(new Event());
		$listener->handle(new ObjectUpdatedEvent($this->entity('PayrollRun', ['status' => 'approved']), $this->entity('PayrollRun', ['status' => 'draft'])));
	}//end testTheApprovalListenerSurvivesAFailingClaimStep()

	/**
	 * The route listener stamps the default on a claim created approved, and
	 * ignores other events.
	 *
	 * @return void
	 */
	public function testTheRouteListenerOnCreateAndOtherEvents(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getExpenseReimbursementRoute')->willReturn('payroll');
		$listener = new ExpenseRouteListener(settings: $settings, marker: new InternalWriteMarker());

		$created = new ObjectCreatingEvent($this->entity('Expense', ['status' => 'approved', 'amount' => 10]));
		$listener->handle($created);
		$this->assertSame(['reimbursementRoute' => 'payroll'], $created->getModifiedData());

		$listener->handle(new Event());
		$this->addToAssertionCount(1);
	}//end testTheRouteListenerOnCreateAndOtherEvents()

	/**
	 * The stamp listener keeps the original drafter on an update, stamps
	 * nothing for an allowance without an employee, and survives an
	 * unreadable employee.
	 *
	 * @return void
	 */
	public function testTheStampListenerKeepsTheDrafterAndSurvivesAnUnreadableEmployee(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findObjectData')->willThrowException(new \RuntimeException('down'));
		$listener = new RecurringAllowanceStampListener(gateway: $gateway, userSession: $this->createMock(IUserSession::class), logger: new NullLogger());

		$update = new ObjectUpdatingEvent(
			$this->entity('RecurringAllowance', ['employeeId' => 'emp-1', 'proposedBy' => 'someone-else']),
			$this->entity('RecurringAllowance', ['employeeId' => 'emp-1', 'proposedBy' => 'hr-demo'])
		);
		$listener->handle($update);
		$this->assertSame(['proposedBy' => 'hr-demo'], $update->getModifiedData());

		$created = new ObjectCreatingEvent($this->entity('RecurringAllowance', ['kind' => 'other']));
		$listener->handle($created);
		$this->assertSame(['proposedBy' => ''], $created->getModifiedData());

		$listener->handle(new Event());
	}//end testTheStampListenerKeepsTheDrafterAndSurvivesAnUnreadableEmployee()

}//end class
