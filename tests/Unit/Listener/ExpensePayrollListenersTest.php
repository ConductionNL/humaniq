<?php

/**
 * Unit tests for ExpenseRouteListener and RecurringAllowanceStampListener.
 *
 * Built on the real OpenRegister pre-save events and ObjectEntity; the
 * stamped payloads are validated against the register's own Expense and
 * RecurringAllowance fragments with Opis (RegisterSchemaValidator).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ExpenseRouteListener;
use OCA\Humaniq\Listener\RecurringAllowanceStampListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The route a claim takes, and who drafted an allowance.
 */
class ExpensePayrollListenersTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	/**
	 * The route listener with the given employer default.
	 *
	 * @param string $default The employer's default route.
	 * @param InternalWriteMarker|null $marker The write marker.
	 *
	 * @return ExpenseRouteListener
	 */
	private function routeListener(string $default = 'payroll', ?InternalWriteMarker $marker = null): ExpenseRouteListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getExpenseReimbursementRoute')->willReturn($default);

		return new ExpenseRouteListener(settings: $settings, marker: ($marker ?? new InternalWriteMarker()));
	}//end routeListener()

	/**
	 * An entity carrying the given data.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $data The object.
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
	 * A claim.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function claim(array $overrides = []): array {
		return array_merge(
			['employeeId' => self::EMPLOYEE, 'title' => 'Treinkaartje', 'category' => 'travel', 'amount' => 27.40, 'status' => 'submitted'],
			$overrides
		);
	}//end claim()

	/**
	 * An update from the old to the new claim.
	 *
	 * @param array<string, mixed> $old The claim before.
	 * @param array<string, mixed> $new The claim after.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $old, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->entity('Expense', $new), $this->entity('Expense', $old));
	}//end update()

	/**
	 * Approval stamps the employer's default route, and the stamped claim is
	 * valid against the Expense fragment.
	 *
	 * @return void
	 */
	public function testApprovalStampsTheEmployersDefaultRoute(): void {
		$approved = $this->claim(['status' => 'approved']);
		$event = $this->update($this->claim(), $approved);

		$this->routeListener('payroll')->handle($event);

		$this->assertSame(['reimbursementRoute' => 'payroll'], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], RegisterSchemaValidator::errors('Expense', array_merge($approved, $event->getModifiedData(), ['payrollRunId' => 'run-5', 'paidInPeriod' => '2026-05'])));
	}//end testApprovalStampsTheEmployersDefaultRoute()

	/**
	 * A route already chosen is kept, and a draft claim gets none.
	 *
	 * @return void
	 */
	public function testAChosenRouteIsKeptAndADraftGetsNone(): void {
		$chosen = $this->update($this->claim(), $this->claim(['status' => 'approved', 'reimbursementRoute' => 'direct']));
		$this->routeListener('payroll')->handle($chosen);
		$this->assertSame([], $chosen->getModifiedData());

		$draft = new ObjectCreatingEvent($this->entity('Expense', $this->claim(['status' => 'draft'])));
		$this->routeListener('payroll')->handle($draft);
		$this->assertSame([], $draft->getModifiedData());
		$this->assertFalse($draft->isPropagationStopped());
	}//end testAChosenRouteIsKeptAndADraftGetsNone()

	/**
	 * A claim with a taxable part cannot take the payroll route; the employer
	 * default does not force it there either.
	 *
	 * @return void
	 */
	public function testATaxableClaimIsRefusedThePayrollRoute(): void {
		$mileage = $this->claim(['status' => 'approved', 'taxableAmount' => 10.50, 'reimbursementRoute' => 'direct']);
		$event = $this->update($mileage, array_merge($mileage, ['reimbursementRoute' => 'payroll']));

		$this->routeListener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('belaste deel', (string)($event->getErrors()['message'] ?? ''));

		$approval = $this->update($this->claim(['taxableAmount' => 10.50]), $this->claim(['status' => 'approved', 'taxableAmount' => 10.50]));
		$this->routeListener('payroll')->handle($approval);
		$this->assertSame(['reimbursementRoute' => 'direct'], $approval->getModifiedData());
		$this->assertFalse($approval->isPropagationStopped());
	}//end testATaxableClaimIsRefusedThePayrollRoute()

	/**
	 * Once a run holds the claim its route is fixed, and a payroll-route claim
	 * is not reimbursed by hand.
	 *
	 * @return void
	 */
	public function testAClaimInARunKeepsItsRouteAndIsNotReimbursedByHand(): void {
		$inRun = $this->claim(['status' => 'approved', 'reimbursementRoute' => 'payroll', 'payrollRunId' => 'run-5']);
		$moved = $this->update($inRun, array_merge($inRun, ['reimbursementRoute' => 'direct']));
		$this->routeListener()->handle($moved);
		$this->assertTrue($moved->isPropagationStopped());

		$byHand = $this->update($inRun, array_merge($inRun, ['status' => 'reimbursed']));
		$this->routeListener()->handle($byHand);
		$this->assertTrue($byHand->isPropagationStopped());

		$direct = $this->claim(['status' => 'approved', 'reimbursementRoute' => 'direct']);
		$paid = $this->update($direct, array_merge($direct, ['status' => 'reimbursed']));
		$this->routeListener()->handle($paid);
		$this->assertFalse($paid->isPropagationStopped());
	}//end testAClaimInARunKeepsItsRouteAndIsNotReimbursedByHand()

	/**
	 * humaniq's own write (the run marking the claim reimbursed) passes.
	 *
	 * @return void
	 */
	public function testHumaniqsOwnWritePasses(): void {
		$marker = new InternalWriteMarker();
		$inRun = $this->claim(['status' => 'approved', 'reimbursementRoute' => 'payroll', 'payrollRunId' => 'run-5']);
		$event = $this->update($inRun, array_merge($inRun, ['status' => 'reimbursed']));

		$marker->runInternal(fn () => $this->routeListener('payroll', $marker)->handle($event));

		$this->assertFalse($event->isPropagationStopped());
	}//end testHumaniqsOwnWritePasses()

	/**
	 * A new allowance names who drafted it and the employee's account and
	 * administration, so the drafter and the employee cannot activate it; the
	 * stamped allowance is valid against the RecurringAllowance fragment.
	 *
	 * @return void
	 */
	public function testANewAllowanceIsStampedWithItsDrafterAndEmployee(): void {
		$store = new FakeObjectStore();
		$store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Piet', 'lastName' => 'Jansen', 'nextcloudUserId' => 'pjansen', 'administrationId' => 'ADM-001']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$listener = new RecurringAllowanceStampListener(gateway: $gateway, userSession: $session, logger: new NullLogger());

		$allowance = ['employeeId' => self::EMPLOYEE, 'kind' => 'thuiswerk', 'amountPerDay' => 2.45, 'daysPerMonth' => 8, 'taxTreatment' => 'gericht-vrijgesteld', 'startDate' => '2026-01-01', 'status' => 'draft'];
		$event = new ObjectCreatingEvent($this->entity('RecurringAllowance', $allowance));
		$listener->handle($event);

		$this->assertSame(['proposedBy' => 'hr-demo', 'userId' => 'pjansen', 'administrationId' => 'ADM-001'], $event->getModifiedData());
		$this->assertSame([], RegisterSchemaValidator::errors('RecurringAllowance', array_merge($allowance, $event->getModifiedData())));

		// An update keeps the drafter; an unknown employee stamps only the drafter.
		$kept = new ObjectUpdatingEvent(
			$this->entity('RecurringAllowance', array_merge($allowance, ['proposedBy' => 'someone', 'employeeId' => 'unknown'])),
			$this->entity('RecurringAllowance', $allowance)
		);
		$listener->handle($kept);
		$this->assertSame([], $kept->getModifiedData());
	}//end testANewAllowanceIsStampedWithItsDrafterAndEmployee()

}//end class
