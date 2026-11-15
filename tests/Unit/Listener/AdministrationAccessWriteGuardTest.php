<?php

/**
 * Unit tests for AdministrationAccessWriteGuard.
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\AdministrationAccessWriteGuard;
use OCA\Humaniq\Service\AdministrationAccessTrust;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Only administrators and HR of the administration write access rows.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */
class AdministrationAccessWriteGuardTest extends TestCase {

	/**
	 * The stored access rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const ROWS = [
		['userId' => 'hr-first', 'administrationId' => 'ADM-001', 'role' => 'hr', '@self' => ['owner' => 'admin']],
		['userId' => 'employee', 'administrationId' => 'ADM-001', 'role' => 'employee', '@self' => ['owner' => 'hr-first']],
	];

	/**
	 * The guard acting as one user.
	 *
	 * @param string|null          $uid    The acting user, or null for no session.
	 * @param bool                 $throws Whether reading the rows fails.
	 * @param InternalWriteMarker|null $marker The write marker.
	 *
	 * @return AdministrationAccessWriteGuard
	 */
	private function guard(?string $uid, bool $throws = false, ?InternalWriteMarker $marker = null): AdministrationAccessWriteGuard {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['loadAll', 'resolveSchemaSlug'])
			->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn('AdministrationAccess');
		if ($throws === true) {
			$gateway->method('loadAll')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('loadAll')->willReturn(self::ROWS);
		}

		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isAdmin')->willReturnCallback(static fn (?string $id): bool => $id === 'admin');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new AdministrationAccessWriteGuard(
			gateway: $gateway,
			trust: new AdministrationAccessTrust($roles, $appConfig),
			marker: ($marker ?? new InternalWriteMarker()),
			session: $session,
			logger: new NullLogger()
		);
	}//end guard()

	/**
	 * An access row entity.
	 *
	 * @param array<string, mixed> $payload The row.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('AdministrationAccess');
		$entity->setObject($payload);

		return $entity;
	}//end entity()

	/**
	 * A user who is not HR of the administration cannot give themselves the
	 * hr role there.
	 *
	 * @return void
	 */
	public function testSelfGrantIsRefused(): void {
		$event = new ObjectCreatingEvent(self::entity(['userId' => 'employee', 'administrationId' => 'ADM-002', 'role' => 'hr']));

		$this->guard('employee')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertNotSame('', (string)($event->getErrors()['message'] ?? ''));
	}//end testSelfGrantIsRefused()

	/**
	 * An employee of the administration cannot promote their own row either.
	 *
	 * @return void
	 */
	public function testPromotingOwnRowIsRefused(): void {
		$old = self::entity(self::ROWS[1]);
		$new = self::entity(['userId' => 'employee', 'administrationId' => 'ADM-001', 'role' => 'hr']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard('employee')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testPromotingOwnRowIsRefused()

	/**
	 * HR of the administration grants and withdraws there, and an
	 * administrator anywhere.
	 *
	 * @return void
	 */
	public function testAdministratorAndHrOfTheAdministrationMayWrite(): void {
		$create = new ObjectCreatingEvent(self::entity(['userId' => 'new-hire', 'administrationId' => 'ADM-001', 'role' => 'employee']));
		$this->guard('hr-first')->handle($create);
		self::assertFalse($create->isPropagationStopped());

		$delete = new ObjectDeletingEvent(self::entity(self::ROWS[1]));
		$this->guard('hr-first')->handle($delete);
		self::assertFalse($delete->isPropagationStopped());

		$elsewhere = new ObjectCreatingEvent(self::entity(['userId' => 'hr-first', 'administrationId' => 'ADM-002', 'role' => 'hr']));
		$this->guard('admin')->handle($elsewhere);
		self::assertFalse($elsewhere->isPropagationStopped());
	}//end testAdministratorAndHrOfTheAdministrationMayWrite()

	/**
	 * HR of one administration cannot move a row into another one.
	 *
	 * @return void
	 */
	public function testMovingARowToAnotherAdministrationIsRefused(): void {
		$old = self::entity(self::ROWS[1]);
		$new = self::entity(['userId' => 'employee', 'administrationId' => 'ADM-002', 'role' => 'employee']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard('hr-first')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testMovingARowToAnotherAdministrationIsRefused()

	/**
	 * No session, or a failed read, refuses the write.
	 *
	 * @return void
	 */
	public function testFailsClosed(): void {
		$anonymous = new ObjectCreatingEvent(self::entity(['userId' => 'x', 'administrationId' => 'ADM-001', 'role' => 'hr']));
		$this->guard(null)->handle($anonymous);
		self::assertTrue($anonymous->isPropagationStopped());

		$unreadable = new ObjectCreatingEvent(self::entity(['userId' => 'x', 'administrationId' => 'ADM-001', 'role' => 'employee']));
		$this->guard('hr-first', throws: true)->handle($unreadable);
		self::assertTrue($unreadable->isPropagationStopped());
	}//end testFailsClosed()

	/**
	 * humaniq's own writes pass.
	 *
	 * @return void
	 */
	public function testInternalWritesPass(): void {
		$marker = new InternalWriteMarker();
		$event = new ObjectCreatingEvent(self::entity(['userId' => 'x', 'administrationId' => 'ADM-002', 'role' => 'hr']));

		$marker->runInternal(fn () => $this->guard(null, marker: $marker)->handle($event));

		self::assertFalse($event->isPropagationStopped());
	}//end testInternalWritesPass()
}//end class
