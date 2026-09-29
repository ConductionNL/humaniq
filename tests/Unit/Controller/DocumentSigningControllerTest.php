<?php

/**
 * DocumentSigningController tests
 *
 * Who may send a generated HR document for signature (people-esign-hr-documents D4).
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
 * @spec openspec/specs/hr-document-signing/spec.md#REQ-HDS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DocumentSigningController;
use OCA\Humaniq\Service\HrDocumentSigningService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The request-signature endpoint.
 */
class DocumentSigningControllerTest extends TestCase {

	/**
	 * The signing service double.
	 *
	 * @var HrDocumentSigningService&MockObject
	 */
	private HrDocumentSigningService&MockObject $signing;

	/**
	 * The reader double.
	 *
	 * @var RbacObjectReader&MockObject
	 */
	private RbacObjectReader&MockObject $rbac;

	/**
	 * The controller for an HR user or not.
	 *
	 * @param bool $hr Whether the caller is HR.
	 *
	 * @return DocumentSigningController
	 */
	private function controller(bool $hr): DocumentSigningController {
		$this->signing = $this->createMock(HrDocumentSigningService::class);
		$this->rbac = $this->createMock(RbacObjectReader::class);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr.adviseur');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DocumentSigningController(request: $this->createMock(IRequest::class), signing: $this->signing, rbac: $this->rbac, roles: $roles, userSession: $session);
	}//end controller()

	/**
	 * An unreadable document is 404 and nothing is raised.
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentIs404(): void {
		$controller = $this->controller(true);
		$this->rbac->method('findOrNull')->with('doc-1', 'HrGeneratedDocument')->willReturn(null);
		$this->signing->expects(self::never())->method('requestSignature');

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->requestSignature('doc-1')->getStatus());
	}//end testAnUnreadableDocumentIs404()

	/**
	 * A caller outside HR is refused.
	 *
	 * @return void
	 */
	public function testANonHrCallerIs403(): void {
		$controller = $this->controller(false);
		$this->rbac->method('findOrNull')->willReturn(['documentType' => 'getuigschrift']);
		$this->signing->expects(self::never())->method('requestSignature');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->requestSignature('doc-1')->getStatus());
	}//end testANonHrCallerIs403()

	/**
	 * Each outcome maps to its status code, the document id included.
	 *
	 * @return void
	 */
	public function testOutcomesMapToStatusCodes(): void {
		$cases = ['requested' => Http::STATUS_OK, 'existing' => Http::STATUS_OK, 'already-signed' => Http::STATUS_OK, 'refused' => Http::STATUS_BAD_REQUEST, 'failed' => Http::STATUS_CONFLICT, 'skipped-no-docudesk' => Http::STATUS_CONFLICT];
		foreach ($cases as $status => $code) {
			$controller = $this->controller(true);
			$this->rbac->method('findOrNull')->willReturn(['documentType' => 'getuigschrift', 'status' => 'generated']);
			$this->signing->method('requestSignature')->with(['documentType' => 'getuigschrift', 'status' => 'generated', 'id' => 'doc-1'], 'hr.adviseur')->willReturn(['status' => $status, 'message' => 'm', 'signingRequestId' => null, 'signingStatus' => null]);

			self::assertSame($code, $controller->requestSignature('doc-1')->getStatus(), $status);
		}
	}//end testOutcomesMapToStatusCodes()

}//end class
