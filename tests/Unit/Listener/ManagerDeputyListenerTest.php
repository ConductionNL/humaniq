<?php

/**
 * Unit tests for ManagerDeputyListener (self-service-approvals-inbox D2): a deputy record is judged before it is saved, on the real OpenRegister event classes.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ManagerDeputyListener;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ManagerDeputyListenerTest extends TestCase {

	use ApprovalsFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
	}//end setUp()

	public function testARecordWithoutAManagerIsTheWritersOwn(): void {
		$event = new ObjectCreatingEvent($this->entity(['deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01']));
		$this->listener('mila')->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertSame(['managerUserId' => 'mila'], $event->getModifiedData());
	}//end testARecordWithoutAManagerIsTheWritersOwn()

	public function testASelfDeputyIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['deputyUserId' => 'mila', 'from' => '2026-07-14', 'until' => '2026-08-01']));
		$this->listener('mila')->handle($event);

		self::assertSame(['message' => 'A manager cannot be their own deputy.'], $event->getErrors());
		self::assertTrue($event->isPropagationStopped());
	}//end testASelfDeputyIsRefused()

	public function testAReversedPeriodIsRefusedOnUpdate(): void {
		$old = ['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01'];
		$event = new ObjectUpdatingEvent($this->entity(array_merge($old, ['until' => '2026-07-01'])), $this->entity($old));
		$this->listener('mila')->handle($event);

		self::assertSame(['message' => 'The last day comes before the first day.'], $event->getErrors());
	}//end testAReversedPeriodIsRefusedOnUpdate()

	public function testSomeoneCannotMakeThemselvesAnotherManagersDeputy(): void {
		$event = new ObjectCreatingEvent($this->entity(['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01']));
		$this->listener('dirk')->handle($event);

		self::assertNotSame([], $event->getErrors());
	}//end testSomeoneCannotMakeThemselvesAnotherManagersDeputy()

	/**
	 * The whole outcome of every kind of write, allowed and refused, including
	 * an update that takes over another manager's record.
	 *
	 * @return void
	 */
	public function testTheWholeOutcomeOfEveryWrite(): void {
		$own = ['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01'];
		$cases = [
			'create-unnamed' => ['mila', new ObjectCreatingEvent($this->entity(array_diff_key($own, ['managerUserId' => 1])))],
			'create-own' => ['mila', new ObjectCreatingEvent($this->entity($own))],
			'create-for-another' => ['dirk', new ObjectCreatingEvent($this->entity($own))],
			'update-own' => ['mila', new ObjectUpdatingEvent($this->entity(array_merge($own, ['until' => '2026-08-15'])), $this->entity($own))],
			'take-over' => ['dirk', new ObjectUpdatingEvent($this->entity(array_merge($own, ['managerUserId' => 'dirk', 'deputyUserId' => 'mila'])), $this->entity($own))],
			'clear-manager' => ['dirk', new ObjectUpdatingEvent($this->entity(array_merge($own, ['managerUserId' => '', 'deputyUserId' => 'mila'])), $this->entity($own))],
			'self-deputy' => ['mila', new ObjectCreatingEvent($this->entity(array_merge($own, ['deputyUserId' => 'mila'])))],
			'deleted' => ['mila', new \OCA\OpenRegister\Event\ObjectDeletedEvent($this->entity($own))],
		];
		$got = [];
		foreach ($cases as $name => [$uid, $event]) {
			$this->listener($uid)->handle($event);
			$got[$name] = [
				'errors' => method_exists($event, 'getErrors') === true ? $event->getErrors() : null,
				'modified' => method_exists($event, 'getModifiedData') === true ? $event->getModifiedData() : null,
				'stopped' => $event->isPropagationStopped(),
			];
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/manager-deputy-writes.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeOutcomeOfEveryWrite()

	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('dep-x');
		$entity->setSchema('ManagerDeputy');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	private function listener(string $uid): ManagerDeputyListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ManagerDeputyListener(deputies: $this->deputies(), userSession: $session);
	}//end listener()

}//end class
