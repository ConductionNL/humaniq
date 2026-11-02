<?php

/**
 * ExpenseManagerStampListener unit tests
 *
 * The approver stamp on an expense claim over the real gateway and the shared
 * manager resolution, on a fake object store: every category, the claim's own
 * date, the climb past a self stamp, the administration boundary, inert client
 * input, decided claims and humaniq's own writes.
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
 * @spec openspec/changes/restamp-open-manager-records/specs/humaniq-expenses/spec.md#Requirement:-An-expense-claim-of-any-category-SHALL-be-approved-the-way-hours-are
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ExpenseManagerStampListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Expense claims get their approver the way timesheets do.
 *
 * @covers \OCA\Humaniq\Listener\ExpenseManagerStampListener
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 */
class ExpenseManagerStampListenerTest extends TestCase {

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The write marker the listener reads.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * Sanne manages Consultancy and sits in it, Pieter manages Directie
	 * above it, Tim is placed in Consultancy. Until June Tim sat in Support,
	 * managed by Kees. Everyone is in ADM-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', 'emp-sanne', ['nextcloudUserId' => 'sanne', 'administrationId' => 'ADM-1']);
		$this->store->seed('Employee', 'emp-pieter', ['nextcloudUserId' => 'pieter', 'administrationId' => 'ADM-1']);
		$this->store->seed('Employee', 'emp-tim', ['nextcloudUserId' => ' tim ', 'administrationId' => 'ADM-1']);
		$this->store->seed('Employee', 'emp-kees', ['nextcloudUserId' => 'kees', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgUnit', 'unit-consultancy', ['managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgUnit', 'unit-directie', ['managerId' => 'emp-pieter', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgUnit', 'unit-support', ['managerId' => 'emp-kees', 'administrationId' => 'ADM-1']);
		$this->store->seed('OrgAssignment', 'as-sanne', ['employeeId' => 'emp-sanne', 'orgUnitId' => 'unit-consultancy', 'endDate' => '']);
		$this->store->seed('OrgAssignment', 'as-tim-old', ['employeeId' => 'emp-tim', 'orgUnitId' => 'unit-support', 'startDate' => '2025-01-01', 'endDate' => '2026-05-31']);
		$this->store->seed('OrgAssignment', 'as-tim', ['employeeId' => 'emp-tim', 'orgUnitId' => 'unit-consultancy', 'startDate' => '2026-06-01', 'endDate' => '']);
	}//end setUp()

	/**
	 * The listener over the fake register.
	 *
	 * @return ExpenseManagerStampListener
	 */
	private function listener(): ExpenseManagerStampListener {
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

		return new ExpenseManagerStampListener(gateway: $gateway, marker: $this->marker, logger: new NullLogger());
	}//end listener()

	/**
	 * An entity of a schema.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ex-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Create a claim and return the modified data.
	 *
	 * @param array<string, mixed> $claim The claim.
	 *
	 * @return array<string, mixed>
	 */
	private function created(array $claim): array {
		$event = new ObjectCreatingEvent($this->entity('Expense', $claim));
		$this->listener()->handle($event);

		return $event->getModifiedData();
	}//end created()

	/**
	 * A claim of every category gets the resolved manager, the claimant's
	 * account and administration; what the client sent is ignored.
	 *
	 * @return void
	 */
	public function testAClaimOfEveryCategoryGetsTheResolvedApprover(): void {
		foreach (['travel', 'accommodation', 'meals', 'supplies', 'software', 'training', 'other'] as $category) {
			$stamped = $this->created(['employeeId' => 'emp-tim', 'category' => $category, 'status' => 'draft', 'expenseDate' => '2026-10-01', 'managerUserId' => 'tim', 'userId' => 'pieter', 'administrationId' => 'ADM-9']);

			self::assertSame(['userId' => 'tim', 'managerUserId' => 'sanne', 'administrationId' => 'ADM-1'], $stamped, $category);
		}
	}//end testAClaimOfEveryCategoryGetsTheResolvedApprover()

	/**
	 * The manager's own claim goes to the manager above, as their hours do.
	 *
	 * @return void
	 */
	public function testAManagersOwnClaimGoesToTheManagerAbove(): void {
		$stamped = $this->created(['employeeId' => 'emp-sanne', 'category' => 'meals', 'status' => 'draft']);

		self::assertSame('pieter', $stamped['managerUserId']);
		self::assertSame('sanne', $stamped['userId']);
	}//end testAManagersOwnClaimGoesToTheManagerAbove()

	/**
	 * The approver is resolved for the claim's date, else today.
	 *
	 * @return void
	 */
	public function testTheApproverIsResolvedForTheClaimsDate(): void {
		self::assertSame('kees', $this->created(['employeeId' => 'emp-tim', 'status' => 'draft', 'expenseDate' => '2026-05-15T12:00:00'])['managerUserId']);
		self::assertSame('sanne', $this->created(['employeeId' => 'emp-tim', 'status' => 'draft', 'expenseDate' => 'x2026-05-15'])['managerUserId']);
		self::assertSame('sanne', $this->created(['employeeId' => 'emp-tim', 'status' => 'draft'])['managerUserId']);
	}//end testTheApproverIsResolvedForTheClaimsDate()

	/**
	 * A manager outside the employee's administration is never stamped.
	 *
	 * @return void
	 */
	public function testAnApproverOutsideTheAdministrationIsNotStamped(): void {
		$this->store->state->objects['OrgUnit']['unit-directie']['administrationId'] = 'ADM-2';

		self::assertNull($this->created(['employeeId' => 'emp-sanne', 'status' => 'draft', 'managerUserId' => 'pieter'])['managerUserId']);
	}//end testAnApproverOutsideTheAdministrationIsNotStamped()

	/**
	 * A submit is stamped again, for today's org chart, and the modified data
	 * of the listeners before it is kept.
	 *
	 * @return void
	 */
	public function testASubmitIsRestampedAndEarlierStampsAreKept(): void {
		$stored = ['employeeId' => 'emp-tim', 'category' => 'supplies', 'status' => 'draft', 'managerUserId' => 'kees', 'userId' => 'tim', 'administrationId' => 'ADM-1'];
		$event = new ObjectUpdatingEvent(
			$this->entity('Expense', array_merge($stored, ['status' => 'submitted'])),
			$this->entity('Expense', $stored)
		);
		$event->setModifiedData(['submittedAt' => '2026-10-02T09:00:00Z', 'managerUserId' => 'stale']);

		$this->listener()->handle($event);

		self::assertSame(
			['submittedAt' => '2026-10-02T09:00:00Z', 'managerUserId' => 'sanne', 'userId' => 'tim', 'administrationId' => 'ADM-1'],
			$event->getModifiedData()
		);
	}//end testASubmitIsRestampedAndEarlierStampsAreKept()

	/**
	 * An update without an employee id in the payload uses the stored one.
	 *
	 * @return void
	 */
	public function testAnUpdateFallsBackToTheStoredEmployee(): void {
		$stored = ['employeeId' => 'emp-tim', 'status' => 'draft'];
		$event = new ObjectUpdatingEvent($this->entity('Expense', ['status' => 'submitted']), $this->entity('Expense', $stored));

		$this->listener()->handle($event);

		self::assertSame('sanne', $event->getModifiedData()['managerUserId']);
	}//end testAnUpdateFallsBackToTheStoredEmployee()

	/**
	 * Approved and reimbursed claims keep the stamp they were decided under.
	 *
	 * @return void
	 */
	public function testDecidedClaimsKeepTheirStamp(): void {
		foreach (['approved', 'reimbursed', ' approved '] as $status) {
			$stored = ['employeeId' => 'emp-tim', 'status' => 'submitted', 'managerUserId' => 'kees'];
			$event = new ObjectUpdatingEvent($this->entity('Expense', array_merge($stored, ['status' => $status])), $this->entity('Expense', $stored));

			$this->listener()->handle($event);

			self::assertSame([], $event->getModifiedData(), $status);
		}
	}//end testDecidedClaimsKeepTheirStamp()

	/**
	 * humaniq's own writes, other schemas and claims without an employee
	 * pass untouched.
	 *
	 * @return void
	 */
	public function testOwnWritesOtherSchemasAndClaimsWithoutAnEmployeePass(): void {
		$claim = ['employeeId' => 'emp-tim', 'status' => 'draft'];
		$internal = new ObjectCreatingEvent($this->entity('Expense', $claim));
		$this->marker->runInternal(fn () => $this->listener()->handle($internal));
		self::assertSame([], $internal->getModifiedData());

		$other = new ObjectCreatingEvent($this->entity('LeaveRequest', $claim));
		$this->listener()->handle($other);
		self::assertSame([], $other->getModifiedData());

		self::assertSame([], $this->created(['employeeId' => ' ', 'status' => 'draft', 'managerUserId' => 'x']));
	}//end testOwnWritesOtherSchemasAndClaimsWithoutAnEmployeePass()

	/**
	 * An unknown employee keeps the stored values rather than wiping them.
	 *
	 * @return void
	 */
	public function testAnUnknownEmployeeKeepsTheStoredValues(): void {
		$stored = ['employeeId' => 'emp-gone', 'status' => 'draft', 'managerUserId' => 'kees', 'userId' => 'gone', 'administrationId' => 'ADM-1'];
		$event = new ObjectUpdatingEvent($this->entity('Expense', array_merge($stored, ['managerUserId' => 'forged'])), $this->entity('Expense', $stored));

		$this->listener()->handle($event);

		self::assertSame(['userId' => 'gone', 'managerUserId' => 'kees', 'administrationId' => 'ADM-1'], $event->getModifiedData());
		self::assertSame(['userId' => null, 'managerUserId' => null, 'administrationId' => null], $this->created(['employeeId' => 'emp-gone', 'status' => 'draft', 'managerUserId' => 'forged']));
	}//end testAnUnknownEmployeeKeepsTheStoredValues()

}//end class
