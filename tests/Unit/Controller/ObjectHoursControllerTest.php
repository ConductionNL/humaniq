<?php
/**
 * ObjectHoursController tests
 *
 * The week's hours on an object are answered to a signed-in caller only, a
 * request without the object or with a day that is not a day is refused
 * before anything is read, and a failed read is a 500, never a zero.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ObjectHoursController;
use OCA\Humaniq\Service\HostObjectAccess;
use OCA\Humaniq\Service\ObjectWeekHours;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Humaniq\Controller\ObjectHoursController
 */
class ObjectHoursControllerTest extends TestCase {

	/**
	 * Build the subject with or without a signed-in caller.
	 *
	 * @param ObjectWeekHours&MockObject $weekHours The week reader.
	 * @param string|null                $uid       The caller, or null for no session.
	 * @param bool                       $readable  Whether the caller may read the host object.
	 *
	 * @return ObjectHoursController The subject.
	 */
	private function controller(ObjectWeekHours&MockObject $weekHours, ?string $uid = 'pieter', bool $readable = true): ObjectHoursController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$hosts = $this->createMock(HostObjectAccess::class);
		$hosts->method('canRead')->willReturnCallback(
			static fn (string $type, string $ref): bool => $readable === true && $type === 'dossiq:case' && $ref === 'case-1'
		);

		return new ObjectHoursController($this->createMock(IRequest::class), $session, $weekHours, $hosts, new NullLogger());
	}//end controller()

	/**
	 * A signed-in caller gets the week of the day asked for.
	 *
	 * @return void
	 */
	public function testACallerGetsTheWeek(): void {
		$weekHours = $this->createMock(ObjectWeekHours::class);
		$weekHours->expects($this->once())->method('forObject')
			->with('dossiq:case', 'case-1', $this->callback(static fn ($day): bool => $day->format('Y-m-d') === '2026-10-08'))
			->willReturn(['week' => 41, 'total' => 11.5, 'people' => []]);

		$response = $this->controller($weekHours)->week('dossiq:case', 'case-1', '2026-10-08');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(41, $response->getData()['week']);
	}//end testACallerGetsTheWeek()

	/**
	 * No session, no object, or a day that is not a day: refused, nothing read.
	 *
	 * @return void
	 */
	public function testRefusalsReadNothing(): void {
		$weekHours = $this->createMock(ObjectWeekHours::class);
		$weekHours->expects($this->never())->method('forObject');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($weekHours, null)->week('dossiq:case', 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($weekHours)->week('', 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($weekHours)->week('dossiq:case', 'case-1', '2026-13-40')->getStatus());
	}//end testRefusalsReadNothing()

	/**
	 * A caller who may not read the object gets 404, as for an object that is
	 * not there, and nothing about its hours is read.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotReadTheObjectGetsNotFound(): void {
		$weekHours = $this->createMock(ObjectWeekHours::class);
		$weekHours->expects($this->never())->method('forObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($weekHours, 'anouk', false)->week('dossiq:case', 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($weekHours)->week('dossiq:case', 'case-other')->getStatus());
	}//end testACallerWhoMayNotReadTheObjectGetsNotFound()

	/**
	 * A failed read answers 500 and no total.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotAZero(): void {
		$weekHours = $this->createMock(ObjectWeekHours::class);
		$weekHours->method('forObject')->willThrowException(new RuntimeException('register down'));

		$response = $this->controller($weekHours)->week('dossiq:case', 'case-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('total', $response->getData());
	}//end testAFailedReadIsNotAZero()
}//end class
