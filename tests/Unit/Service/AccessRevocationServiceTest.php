<?php

/**
 * Unit tests for disabling a leaver's Nextcloud account.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AccessRevocationService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The refusals of design D2, and the stamp.
 */
class AccessRevocationServiceTest extends TestCase {

	private const NOW = '2026-09-30T08:00:00+00:00';

	/** @var array<string, array<string, mixed>> */
	private array $store = [];

	/** @var array<int, array{payload: array<string, mixed>, schema: string, uuid: ?string}> */
	private array $saved = [];

	private HoursRegisterGateway&MockObject $gateway;
	private IUserManager&MockObject $users;
	private IGroupManager&MockObject $groups;

	/** @var array<string, bool> uid => enabled */
	private array $enabled = [];

	protected function setUp(): void {
		$this->store = [
			'case-1' => ['employeeId' => 'emp-1', 'lastWorkingDay' => '2026-09-29', 'reason' => 'opzegging-werkgever', 'status' => 'afronding_gepland', 'toegangIngetrokken' => false, 'notes' => 'keep me', '@self' => ['id' => 'case-1']],
			'emp-1' => ['nextcloudUserId' => 'j.jansen'],
			'emp-admin' => ['nextcloudUserId' => 'boss'],
			'emp-none' => ['nextcloudUserId' => ''],
			'emp-hr' => ['nextcloudUserId' => 'hr-demo'],
		];
		$this->enabled = ['j.jansen' => true, 'boss' => true, 'hr-demo' => true];

		$this->gateway = $this->createMock(HoursRegisterGateway::class);
		$this->gateway->method('findObjectData')->willReturnCallback(fn (string $id): ?array => ($this->store[$id] ?? null));
		$this->gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saved[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
			return new \stdClass();
		});

		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if (isset($this->enabled[$uid]) === false) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled[$uid]);
			$user->method('setEnabled')->willReturnCallback(function (bool $on) use ($uid): void {
				$this->enabled[$uid] = $on;
			});
			return $user;
		});

		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'boss');
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return AccessRevocationService
	 */
	private function service(): AccessRevocationService {
		return new AccessRevocationService($this->gateway, $this->users, $this->groups, new NullLogger());
	}//end service()

	/**
	 * A leaver's account is disabled and the whole case is saved with the stamp.
	 *
	 * @return void
	 */
	public function testTheLeaversAccountIsDisabled(): void {
		$result = $this->service()->revoke(offboardingId: 'case-1', actorUid: 'hr-demo', now: self::NOW);

		self::assertSame(200, $result['status']);
		self::assertFalse($this->enabled['j.jansen']);
		self::assertCount(1, $this->saved);
		$payload = $this->saved[0]['payload'];
		self::assertSame('Offboarding', $this->saved[0]['schema']);
		self::assertSame('case-1', $this->saved[0]['uuid']);
		self::assertTrue($payload['toegangIngetrokken']);
		self::assertSame('hr-demo', $payload['toegangIngetrokkenDoor']);
		self::assertSame(self::NOW, $payload['toegangIngetrokkenOp']);
		self::assertSame('keep me', $payload['notes'], 'OpenRegister replaces the object, so the rest of the case goes along.');
		self::assertSame('afronding_gepland', $payload['status']);
		self::assertArrayNotHasKey('@self', $payload);
		self::assertSame([], array_values(array_diff(array_keys($payload), array_keys(RegisterSchemaValidator::schema('Offboarding')['properties']))), 'Every field written is an Offboarding property; OpenRegister drops the others.');
		self::assertSame([], RegisterSchemaValidator::errors('Offboarding', array_merge($payload, ['employeeId' => '0127394a-be27-48b4-a592-b6a41774b221'])));
	}//end testTheLeaversAccountIsDisabled()

	/**
	 * An empty uid ticks the box and says there was no account.
	 *
	 * @return void
	 */
	public function testAnEmployeeWithoutAnAccountIsRecorded(): void {
		$this->store['case-1']['employeeId'] = 'emp-none';
		$result = $this->service()->revoke(offboardingId: 'case-1', actorUid: 'hr-demo', now: self::NOW);

		self::assertSame(200, $result['status']);
		self::assertTrue($this->saved[0]['payload']['toegangIngetrokken']);
		self::assertSame('No Nextcloud account', $this->saved[0]['payload']['toegangIngetrokkenToelichting']);
	}//end testAnEmployeeWithoutAnAccountIsRecorded()

	/**
	 * The actor's own account is refused.
	 *
	 * @return void
	 */
	public function testTheActorsOwnAccountIsRefused(): void {
		$this->store['case-1']['employeeId'] = 'emp-hr';
		$result = $this->service()->revoke(offboardingId: 'case-1', actorUid: 'hr-demo', now: self::NOW);

		self::assertSame(409, $result['status']);
		self::assertSame('You cannot disable your own account.', $result['message']);
		self::assertTrue($this->enabled['hr-demo']);
		self::assertSame([], $this->saved);
	}//end testTheActorsOwnAccountIsRefused()

	/**
	 * An account in the admin group stays enabled and the box stays unticked.
	 *
	 * @return void
	 */
	public function testAnAdministratorAccountIsRefused(): void {
		$this->store['case-1']['employeeId'] = 'emp-admin';
		$result = $this->service()->revoke(offboardingId: 'case-1', actorUid: 'hr-demo', now: self::NOW);

		self::assertSame(409, $result['status']);
		self::assertSame('The account boss is an administrator and is not disabled from humaniq. Disable it in Nextcloud user management.', $result['message']);
		self::assertTrue($this->enabled['boss']);
		self::assertSame([], $this->saved);
	}//end testAnAdministratorAccountIsRefused()

	/**
	 * A second call on a disabled, stamped account changes nothing.
	 *
	 * @return void
	 */
	public function testARepeatCallIsANoOp(): void {
		$this->enabled['j.jansen'] = false;
		$this->store['case-1']['toegangIngetrokken'] = true;
		$this->store['case-1']['toegangIngetrokkenDoor'] = 'hr-demo';
		$this->store['case-1']['toegangIngetrokkenOp'] = '2026-09-29T10:00:00+00:00';

		$result = $this->service()->revoke(offboardingId: 'case-1', actorUid: 'other-hr', now: self::NOW);

		self::assertSame(200, $result['status']);
		self::assertSame('2026-09-29T10:00:00+00:00', $result['offboarding']['toegangIngetrokkenOp']);
		self::assertSame([], $this->saved);
	}//end testARepeatCallIsANoOp()

	/**
	 * An account name that Nextcloud does not know is refused, not ticked.
	 *
	 * @return void
	 */
	public function testAnUnknownAccountIsRefused(): void {
		$this->store['emp-1']['nextcloudUserId'] = 'typo';
		$result = $this->service()->revoke(offboardingId: 'case-1', actorUid: 'hr-demo', now: self::NOW);

		self::assertSame(409, $result['status']);
		self::assertSame([], $this->saved);
	}//end testAnUnknownAccountIsRefused()

	/**
	 * The daily run revokes open cases whose last working day has passed, and
	 * leaves the rest.
	 *
	 * @return void
	 */
	public function testTheDailyRunTakesOnlyDueOpenCases(): void {
		$this->store['case-2'] = ['employeeId' => 'emp-1', 'lastWorkingDay' => '2026-10-15', 'status' => 'aangekondigd', 'toegangIngetrokken' => false];
		$this->gateway->method('loadAll')->willReturn([
			['id' => 'case-1'] + $this->store['case-1'],
			['id' => 'case-2'] + $this->store['case-2'],
			['id' => 'case-3', 'employeeId' => 'emp-1', 'lastWorkingDay' => '2026-01-01', 'status' => 'geannuleerd', 'toegangIngetrokken' => false],
		]);

		$done = $this->service()->revokeDue(today: '2026-09-30', now: self::NOW);

		self::assertSame(['case-1'], $done);
		self::assertCount(1, $this->saved);
		self::assertSame('humaniq', $this->saved[0]['payload']['toegangIngetrokkenDoor']);
	}//end testTheDailyRunTakesOnlyDueOpenCases()

}//end class
