<?php

/**
 * Unit tests for AnnouncementController (self-service-announcements-and-digest D2).
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\AnnouncementController;
use OCA\Humaniq\Service\AnnouncementService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */
class AnnouncementControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var AnnouncementService&MockObject
	 */
	private AnnouncementService&MockObject $service;

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(AnnouncementService::class);
	}//end setUp()

	/**
	 * The caller's announcements come back under `announcements`.
	 *
	 * @return void
	 */
	public function testMineListsTheCallersAnnouncements(): void {
		$this->service->expects(self::once())->method('mine')->with('anna', self::isType('string'))->willReturn([['id' => 'ann-all', 'title' => 'Regeling']]);

		$response = $this->controller('anna')->mine();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('ann-all', $response->getData()['announcements'][0]['id']);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->mine()->getStatus());
	}//end testMineListsTheCallersAnnouncements()

	/**
	 * A confirmation answers with the service's status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function testConfirmPassesTheServiceStatusOn(): void {
		$this->service->method('confirm')->willReturnOnConsecutiveCalls(
			['status' => 201, 'message' => null, 'confirmation' => ['announcementId' => 'ann-all']],
			['status' => 409, 'message' => 'Al bevestigd.', 'confirmation' => null]
		);

		self::assertSame(Http::STATUS_CREATED, $this->controller('anna')->confirm('ann-all')->getStatus());
		$again = $this->controller('anna')->confirm('ann-all');
		self::assertSame(Http::STATUS_CONFLICT, $again->getStatus());
		self::assertSame('Al bevestigd.', $again->getData()['message']);
	}//end testConfirmPassesTheServiceStatusOn()

	/**
	 * Only HR or an administrator sees who confirmed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function testOnlyHrSeesTheConfirmations(): void {
		$this->service->method('overview')->willReturn(['announcementId' => 'ann-all', 'total' => 2, 'confirmed' => 1, 'rows' => []]);

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller('anna')->confirmations('ann-all')->getStatus());
		self::assertSame(Http::STATUS_OK, $this->controller('hr.user')->confirmations('ann-all')->getStatus());
		self::assertSame(1, $this->controller('hr.user')->confirmations('ann-all')->getData()['confirmed']);
	}//end testOnlyHrSeesTheConfirmations()

	/**
	 * An announcement that does not exist is 404 for HR too.
	 *
	 * @return void
	 */
	public function testAnUnknownAnnouncementIs404(): void {
		$this->service->method('overview')->willReturn(null);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller('hr.user')->confirmations('ann-missing')->getStatus());
	}//end testAnUnknownAnnouncementIs404()

	/**
	 * The controller for a caller.
	 *
	 * @param string|null $uid The caller, null when logged out.
	 *
	 * @return AnnouncementController
	 */
	private function controller(?string $uid): AnnouncementController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => $who === 'hr.user' && $group === HumaniqRoles::HR_GROUP);

		return new AnnouncementController(
			request: $this->createMock(IRequest::class),
			announcements: $this->service,
			roles: new HumaniqRoles($groups),
			userSession: $session
		);
	}//end controller()

}//end class
