<?php

/**
 * Expense pre-save chain tests
 *
 * The Expense pre-save listeners run on one event, in the order boot()
 * subscribes them: TravelAmountListener, ApprovalDecisionStampListener,
 * ExpenseRouteListener. OpenRegister's setModifiedData() replaces what was
 * set before, so each listener must keep the stamps of the ones before it.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ApprovalDecisionStampListener;
use OCA\Humaniq\Listener\ExpensePayoutFieldsListener;
use OCA\Humaniq\Listener\ExpenseRouteListener;
use OCA\Humaniq\Listener\TravelAmountListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TravelAllowanceCalculator;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Expense pre-save listeners keep each other's stamps.
 *
 * @covers \OCA\Humaniq\Listener\TravelAmountListener
 * @covers \OCA\Humaniq\Listener\ApprovalDecisionStampListener
 * @covers \OCA\Humaniq\Listener\ExpenseRouteListener
 * @covers \OCA\Humaniq\Listener\ExpensePayoutFieldsListener
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 * @uses   \OCA\Humaniq\Service\PayrollExpenseFoldService
 * @uses   \OCA\Humaniq\Service\AllowanceSplitter
 * @uses   \OCA\Humaniq\Service\TravelAllowanceCalculator
 * @uses   \OCA\Humaniq\Standards\Checks\NlTravelExpenseChecks
 * @uses   \OCA\Humaniq\Standards\RuleCatalogue
 */
class ExpensePreSaveChainTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	/**
	 * A mileage claim of 150 km.
	 *
	 * @var array<string, mixed>
	 */
	private const CLAIM = ['employeeId' => self::EMPLOYEE, 'title' => 'Klantbezoek', 'category' => 'travel', 'travelType' => 'business', 'distanceKm' => 150, 'transportMode' => 'car', 'fuelType' => 'gasoline'];

	/**
	 * The listeners in the order boot() subscribes them.
	 *
	 * @return list<object>
	 */
	private function chain(): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('getMileageRatePerKm')->willReturn(null);
		$settings->method('getExpenseReimbursementRoute')->willReturn('payroll');
		$store = new FakeObjectStore();
		$store->seed('Employee', self::EMPLOYEE, ['nextcloudUserId' => 'pjansen', 'administrationId' => 'ADM-001']);
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dirk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$marker = new InternalWriteMarker();

		return [
			new TravelAmountListener(gateway: $gateway, calculator: new TravelAllowanceCalculator(), settings: $settings, marker: $marker, logger: new NullLogger()),
			new ApprovalDecisionStampListener($session),
			new ExpenseRouteListener(settings: $settings, marker: $marker),
			new ExpensePayoutFieldsListener(gateway: $gateway, marker: $marker, userSession: $session),
		];
	}//end chain()

	/**
	 * Run a move from one stored claim to the saved one through the chain.
	 *
	 * @param array<string, mixed> $stored The stored claim.
	 * @param array<string, mixed> $saved The claim being saved.
	 *
	 * @return array<string, mixed> The modified data after the chain.
	 */
	private function update(array $stored, array $saved): array {
		$event = new ObjectUpdatingEvent($this->entity(data: $saved), $this->entity(data: $stored));
		foreach ($this->chain() as $listener) {
			$listener->handle($event);
		}

		return $event->getModifiedData();
	}//end update()

	/**
	 * An Expense entity.
	 *
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ex-1');
		$entity->setSchema('Expense');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A submit keeps the calculated amount and gets its submit moment.
	 *
	 * @return void
	 */
	public function testASubmitKeepsTheCalculatedAmount(): void {
		$stamped = $this->update(array_merge(self::CLAIM, ['status' => 'draft']), array_merge(self::CLAIM, ['status' => 'submitted']));

		self::assertSame(34.5, $stamped['amount'] ?? null);
		self::assertSame('calculated', $stamped['amountSource'] ?? null);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)($stamped['submittedAt'] ?? ''));
	}//end testASubmitKeepsTheCalculatedAmount()

	/**
	 * An approve keeps who decided and when, and gets its route.
	 *
	 * @return void
	 */
	public function testAnApproveKeepsWhoDecided(): void {
		$claim = array_merge(self::CLAIM, ['amount' => 34.5, 'taxableAmount' => 0.0]);
		$stamped = $this->update(array_merge($claim, ['status' => 'submitted']), array_merge($claim, ['status' => 'approved']));

		self::assertSame('dirk', $stamped['approvedBy'] ?? null);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)($stamped['approvedAt'] ?? ''));
		self::assertSame('payroll', $stamped['reimbursementRoute'] ?? null);
	}//end testAnApproveKeepsWhoDecided()

	/**
	 * A create through the chain keeps what a listener before it set.
	 *
	 * @return void
	 */
	public function testACreateKeepsEarlierStamps(): void {
		$event = new ObjectCreatingEvent($this->entity(data: array_merge(self::CLAIM, ['status' => 'draft'])));
		$event->setModifiedData(['userId' => 'pjansen']);
		$this->chain()[0]->handle($event);

		self::assertSame('pjansen', $event->getModifiedData()['userId'] ?? null);
		self::assertSame(34.5, $event->getModifiedData()['amount'] ?? null);
	}//end testACreateKeepsEarlierStamps()

	/**
	 * An edit of an approved claim through the chain keeps what is paid and
	 * when it was approved.
	 *
	 * @return void
	 */
	public function testAnEditOfAnApprovedClaimKeepsWhatIsPaid(): void {
		$approved = array_merge(self::CLAIM, ['status' => 'approved', 'amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'currency' => 'EUR', 'approvedBy' => 'dirk', 'approvedAt' => '2026-10-01T09:00:00Z', 'reimbursementRoute' => 'payroll']);
		$stamped = $this->update($approved, array_merge($approved, ['amount' => 3450.0, 'approvedAt' => '2026-11-30T09:00:00Z', 'payrollRunId' => 'run-x']));

		self::assertSame(34.5, $stamped['amount'] ?? null);
		self::assertSame('2026-10-01T09:00:00Z', $stamped['approvedAt'] ?? null);
		self::assertArrayHasKey('payrollRunId', $stamped);
		self::assertNull($stamped['payrollRunId']);
	}//end testAnEditOfAnApprovedClaimKeepsWhatIsPaid()

	/**
	 * What payroll would pay for a claim in October 2026, in cents.
	 *
	 * @param array<string, mixed> $claim The claim as saved.
	 *
	 * @return int
	 */
	private function paidCents(array $claim): int {
		$fold = new PayrollExpenseFoldService($this->createMock(\OCA\Humaniq\Service\RecordStoreInterface::class), new InternalWriteMarker());

		return $fold->foldFor(['expenses' => [$claim], 'allowances' => [], 'arrangementsById' => []], self::EMPLOYEE, '2026-10', 'run-1')['claimCents'];
	}//end paidCents()

	/**
	 * A create straight into approved is refused, so payroll pays nothing;
	 * a draft create starts without decision and run fields.
	 *
	 * @return void
	 */
	public function testAClaimCannotBeCreatedAsApproved(): void {
		$forged = ['employeeId' => self::EMPLOYEE, 'title' => 't', 'category' => 'meals', 'amount' => 9999.0, 'currency' => 'EUR', 'status' => 'approved', 'approvedAt' => '2026-10-02T10:00:00Z', 'approvedBy' => 'dirk', 'reimbursementRoute' => 'payroll'];
		self::assertSame(999900, $this->paidCents($forged));

		$event = new ObjectCreatingEvent($this->entity(data: $forged));
		foreach ($this->chain() as $listener) {
			if ($event->isPropagationStopped() === false) {
				$listener->handle($event);
			}
		}

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'Een nieuwe declaratie begint als concept of als ingediend.'], $event->getErrors());

		$draft = new ObjectCreatingEvent($this->entity(data: array_merge($forged, ['status' => 'draft', 'payrollRunId' => 'run-1', 'paidInPeriod' => '2026-10'])));
		foreach ($this->chain() as $listener) {
			$listener->handle($draft);
		}

		self::assertFalse($draft->isPropagationStopped());
		$saved = array_merge($forged, ['status' => 'draft'], $draft->getModifiedData());
		foreach (['approvedBy', 'approvedAt', 'payrollRunId', 'paidInPeriod'] as $field) {
			self::assertArrayHasKey($field, $saved);
			self::assertNull($saved[$field], $field);
		}
	}//end testAClaimCannotBeCreatedAsApproved()

	/**
	 * An edit of an approved claim cannot move it from the direct route to
	 * payroll, so payroll does not pay it.
	 *
	 * @return void
	 */
	public function testAnApprovedClaimKeepsItsRoute(): void {
		$approved = array_merge(self::CLAIM, ['status' => 'approved', 'category' => 'meals', 'amount' => 10.0, 'currency' => 'EUR', 'approvedBy' => 'dirk', 'approvedAt' => '2026-10-02T10:00:00Z', 'reimbursementRoute' => 'direct', 'taxableAmount' => null]);
		$edit = array_merge($approved, ['reimbursementRoute' => 'payroll']);
		$stamped = $this->update($approved, $edit);

		self::assertSame('direct', $stamped['reimbursementRoute'] ?? null);
		self::assertSame(0, $this->paidCents(array_merge($edit, $stamped)));
		self::assertSame(1000, $this->paidCents($edit));
	}//end testAnApprovedClaimKeepsItsRoute()

	/**
	 * HR reopens an approved claim through the chain: it is submitted again
	 * with its amounts, without approval or route, and payroll does not pay
	 * it until it is approved again.
	 *
	 * @return void
	 */
	public function testHrReopensAnApprovedClaim(): void {
		$approved = array_merge(self::CLAIM, ['status' => 'approved', 'amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0, 'currency' => 'EUR', 'approvedBy' => 'sanne', 'approvedAt' => '2026-10-01T09:00:00Z', 'reimbursementRoute' => 'payroll', 'submittedAt' => '2026-09-30T09:00:00Z']);
		self::assertSame(3450, $this->paidCents($approved));

		$reopen = array_merge($approved, ['status' => 'submitted']);
		$stamped = $this->update($approved, $reopen);
		$saved = array_merge($reopen, $stamped);

		self::assertSame(34.5, $saved['amount']);
		self::assertNull($saved['approvedBy']);
		self::assertNull($saved['approvedAt']);
		self::assertNull($saved['reimbursementRoute']);
		self::assertNotSame('2026-09-30T09:00:00Z', $saved['submittedAt']);
		self::assertSame(0, $this->paidCents($saved));
	}//end testHrReopensAnApprovedClaim()

	/**
	 * RW32's probe through the chain: a forged reopen of a claim a payroll
	 * run holds is refused, and the claim is not saved as submitted.
	 *
	 * @return void
	 */
	public function testAForgedReopenOfAClaimInARunIsRefused(): void {
		$held = array_merge(self::CLAIM, ['status' => 'approved', 'amount' => 10.0, 'currency' => 'EUR', 'userId' => 'hr-anna', 'approvedBy' => 'sanne', 'approvedAt' => '2026-10-02T10:00:00Z', 'reimbursementRoute' => 'payroll', 'payrollRunId' => 'run-1']);
		$event = new ObjectUpdatingEvent($this->entity(data: array_merge($held, ['status' => 'submitted', 'userId' => 'someone', 'approvedBy' => '', 'payrollRunId' => ''])), $this->entity(data: $held));
		foreach ($this->chain() as $listener) {
			if ($event->isPropagationStopped() === false) {
				$listener->handle($event);
			}
		}

		self::assertTrue($event->isPropagationStopped());
	}//end testAForgedReopenOfAClaimInARunIsRefused()

}//end class
