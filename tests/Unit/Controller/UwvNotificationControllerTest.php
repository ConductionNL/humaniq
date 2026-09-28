<?php

/**
 * Contract tests for POST /api/sick-leave/{id}/uwv-notification.
 *
 * The case resolves under the caller's own RBAC first (an unreadable case is
 * a 404), then only an administrator or HR in the active administration may
 * generate (403 otherwise), and a refused case is a 400.
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\UwvNotificationController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The 42-week notification endpoint's 404, 403, 400 and 200.
 */
class UwvNotificationControllerTest extends TestCase {

	/**
	 * @var HrDocumentService&MockObject
	 */
	private HrDocumentService&MockObject $documents;

	/**
	 * The controller for a caller.
	 *
	 * @param bool $readable Whether the caller's RBAC reads the case.
	 * @param bool $admin Whether the caller is an admin.
	 * @param string|null $role The caller's role in the active administration.
	 *
	 * @return UwvNotificationController
	 */
	private function controller(bool $readable, bool $admin, ?string $role): UwvNotificationController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($readable === true ? ['id' => 'case-1', 'status' => 'gemeld'] : null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-adviseur');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('getActiveAdministrationRole')->willReturn($role);
		$this->documents = $this->createMock(HrDocumentService::class);

		return new UwvNotificationController($this->createMock(IRequest::class), $this->documents, $rbac, $administrations, $groups, $session);
	}//end controller()

	/**
	 * An unreadable case is a 404 and nothing is generated.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIs404(): void {
		$controller = $this->controller(false, true, 'hr');
		$this->documents->expects($this->never())->method('generateUwvNotification');

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->generate('case-1')->getStatus());
	}//end testAnUnreadableCaseIs404()

	/**
	 * A manager or employee who can read the case gets 403.
	 *
	 * @return void
	 */
	public function testANonHrCallerIs403(): void {
		$controller = $this->controller(true, false, 'employee');
		$this->documents->expects($this->never())->method('generateUwvNotification');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->generate('case-1')->getStatus());
	}//end testANonHrCallerIs403()

	/**
	 * HR generates; a refused case is a 400.
	 *
	 * @return void
	 */
	public function testHrGeneratesAndARefusalIs400(): void {
		$controller = $this->controller(true, false, 'hr');
		$this->documents->method('generateUwvNotification')->with('case-1', 'hr-adviseur')->willReturnOnConsecutiveCalls(
			['status' => 'generated', 'generatedDocumentId' => 'doc-1'],
			['status' => 'refused-recovered', 'message' => 'De medewerker is hersteld.']
		);

		self::assertSame(Http::STATUS_OK, $controller->generate('case-1')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->generate('case-1')->getStatus());
	}//end testHrGeneratesAndARefusalIs400()

}//end class
