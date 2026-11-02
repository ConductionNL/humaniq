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
use OCA\Humaniq\Listener\ExpenseRouteListener;
use OCA\Humaniq\Listener\TravelAmountListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
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
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
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
	 * The three listeners in the order boot() subscribes them.
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

}//end class
