<?php

/**
 * Unit tests for ManagerAccessStampListener and ManagerAccessOrgChangeListener
 * (mss-team-scope REQ-MSS-009): the stamps written before a save, and the
 * refresh queued after an org or deputy change.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\BackgroundJob\RefreshManagerAccessNowJob;
use OCA\Humaniq\Listener\ManagerAccessOrgChangeListener;
use OCA\Humaniq\Listener\ManagerAccessStampListener;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ManagerAccessStamps;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ManagerAccessStampListenerTest extends TestCase {

	use ApprovalsFixture;

	/**
	 * The internal write marker.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * Seed the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
		$this->marker = new InternalWriteMarker();
	}//end setUp()

	/**
	 * Listener.
	 *
	 * @param ManagerAccessStamps|null $stamps The stamps.
	 *
	 * @return ManagerAccessStampListener
	 */
	private function listener(?ManagerAccessStamps $stamps = null): ManagerAccessStampListener {
		return new ManagerAccessStampListener(
			stamps: $stamps ?? new ManagerAccessStamps(gateway: $this->gateway(), marker: $this->marker, deputies: $this->deputies(), logger: new NullLogger()),
			logger: new NullLogger(),
			marker: $this->marker
		);
	}//end listener()

	/**
	 * Entity.
	 *
	 * @param string $schema The schema.
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A created record is stamped and a forged stamp is replaced.
	 *
	 * @return void
	 */
	public function testACreatedRecordIsStampedAndAForgedStampIsReplaced(): void {
		$event = new ObjectCreatingEvent($this->entity('LeaveTransaction', 'lt-1', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'sam', 'deputyUserIds' => ['sam']]));
		$this->listener()->handle($event);

		self::assertSame('mila', $event->getModifiedData()['managerUserId']);
		self::assertNotContains('sam', $event->getModifiedData()['deputyUserIds']);
	}//end testACreatedRecordIsStampedAndAForgedStampIsReplaced()

	/**
	 * An employee record is stamped with its own manager.
	 *
	 * @return void
	 */
	public function testAnEmployeeRecordIsStampedWithItsOwnManager(): void {
		$event = new ObjectUpdatingEvent($this->entity('Employee', 'emp-noor', ['lastName' => 'Visser']), $this->entity('Employee', 'emp-noor', ['lastName' => 'Visser']));
		$this->listener()->handle($event);

		self::assertSame('mila', $event->getModifiedData()['managerUserId']);
	}//end testAnEmployeeRecordIsStampedWithItsOwnManager()

	/**
	 * A failed resolution keeps the stored stamps.
	 *
	 * @return void
	 */
	public function testAFailedResolutionKeepsTheStoredStamps(): void {
		$stamps = $this->createMock(ManagerAccessStamps::class);
		$stamps->method('stampsFor')->willThrowException(new \RuntimeException('register down'));
		$stamps->method('schemaFor')->willReturn('SickLeaveCase');
		$stored = ['employeeId' => 'emp-sam', 'managerUserId' => 'mila', 'deputyUserIds' => ['dirk']];
		$event = new ObjectUpdatingEvent($this->entity('SickLeaveCase', 'sl-1', ['employeeId' => 'emp-sam', 'managerUserId' => 'sam']), $this->entity('SickLeaveCase', 'sl-1', $stored));
		$this->listener(stamps: $stamps)->handle($event);

		self::assertSame(['managerUserId' => 'mila', 'deputyUserIds' => ['dirk']], array_intersect_key($event->getModifiedData(), ['managerUserId' => 1, 'deputyUserIds' => 1]));
	}//end testAFailedResolutionKeepsTheStoredStamps()

	/**
	 * Other schemas and humaniqs own writes pass untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndHumaniqsOwnWritesPassUntouched(): void {
		$other = new ObjectCreatingEvent($this->entity('LeaveRequest', 'lr-1', ['employeeId' => 'emp-sam']));
		$this->listener()->handle($other);
		self::assertArrayNotHasKey('managerUserId', $other->getModifiedData());

		$own = new ObjectCreatingEvent($this->entity('CompAdjustment', 'ca-1', ['employeeId' => 'emp-sam']));
		$this->marker->runInternal(fn () => $this->listener()->handle($own));
		self::assertArrayNotHasKey('managerUserId', $own->getModifiedData());
	}//end testOtherSchemasAndHumaniqsOwnWritesPassUntouched()

	/**
	 * An org or deputy change queues one refresh.
	 *
	 * @return void
	 */
	public function testAnOrgOrDeputyChangeQueuesOneRefresh(): void {
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(static function (string $job) use (&$queued): void {
			$queued[] = $job;
		});
		$listener = new ManagerAccessOrgChangeListener(jobList: $jobs, gateway: $this->gateway());

		$listener->handle(new ObjectCreatedEvent($this->entity('ManagerDeputy', 'dep-1', [])));
		$listener->handle(new ObjectCreatedEvent($this->entity('OrgAssignment', 'as-1', [])));
		$listener->handle(new ObjectCreatedEvent($this->entity('LeaveRequest', 'lr-1', [])));

		self::assertSame([RefreshManagerAccessNowJob::class, RefreshManagerAccessNowJob::class], $queued);
	}//end testAnOrgOrDeputyChangeQueuesOneRefresh()

}//end class
