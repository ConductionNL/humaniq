<?php

/**
 * Unit tests for AttendanceClockController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/time-attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Humaniq\Controller\AttendanceClockController;
use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use OCA\Humaniq\Service\AttendanceClockService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The caller, never a parameter, is who clocks (REQ-TA-008).
 *
 * @spec openspec/specs/time-attendance/spec.md#requirement-an-employee-shall-clock-in-and-clock-out-from-my-attendance-req-ta-008
 */
#[CoversClass(AttendanceClockController::class)]
#[UsesClass(AttendanceClockService::class)]
final class AttendanceClockControllerTest extends TestCase {

	/**
	 * A controller for the given caller over a store with the given rows.
	 *
	 * @param string|null                               $uid  The signed-in user, or null.
	 * @param array<string, list<array<string, mixed>>> $rows Rows per schema.
	 *
	 * @return AttendanceClockController
	 */
	private function controller(?string $uid, array $rows = []): AttendanceClockController {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(
			static fn (string $schema, array $filters): array => array_values(
				array_filter(
					($rows[$schema] ?? []),
					static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
				)
			)
		);
		$store->method('save')->willReturn(new \stdClass());

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-03-02T08:30:00+01:00'));

		$service = new AttendanceClockService($store, $this->createMock(ContainerInterface::class), $this->createMock(OpenRegisterAvailabilityInterface::class), $time, $this->createMock(LoggerInterface::class));

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new AttendanceClockController($this->createMock(IRequest::class), $service, $session);
	}//end controller()

	/**
	 * A signed-out request is refused.
	 *
	 * @return void
	 */
	public function testSignedOutCallsAreRefused(): void {
		self::assertSame(401, $this->controller(null)->clockIn()->getStatus());
		self::assertSame(401, $this->controller(null)->clockOut()->getStatus());
	}//end testSignedOutCallsAreRefused()

	/**
	 * The caller clocks in as their own employee; a second clock in answers 409.
	 *
	 * @return void
	 */
	public function testTheCallerClocksInAsThemselves(): void {
		$response = $this->controller('sanne', ['Employee' => [['id' => 'emp-1', 'nextcloudUserId' => 'sanne']]])->clockIn();

		self::assertSame(201, $response->getStatus());
		self::assertSame('emp-1', $response->getData()['record']['employeeId']);
		self::assertSame('sanne', $response->getData()['record']['userId']);

		$open = $this->controller('sanne', ['Employee' => [['id' => 'emp-1', 'nextcloudUserId' => 'sanne']], 'AttendanceRecord' => [['id' => 'a', 'userId' => 'sanne', 'status' => 'open']]]);
		self::assertSame(409, $open->clockIn()->getStatus());
		self::assertSame(409, $this->controller('sanne')->clockOut()->getStatus());
	}//end testTheCallerClocksInAsThemselves()
}//end class
