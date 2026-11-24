<?php

/**
 * ApplicationCvController tests
 *
 * Read CV answers 404 for an application the caller cannot read, 200 only
 * when the profile was filled, 403 when the reader refuses the caller and
 * 422 with the reason otherwise.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Humaniq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ApplicationCvController;
use OCA\Humaniq\Service\CvProfileReader;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The Read CV endpoint.
 */
class ApplicationCvControllerTest extends TestCase {

	/**
	 * The controller over a reader that answers `$status`.
	 *
	 * @param bool        $readable Whether the caller can read the application.
	 * @param string|null $status   The reader's status; null when it must not be asked.
	 *
	 * @return ApplicationCvController
	 */
	private function controller(bool $readable, ?string $status): ApplicationCvController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($readable ? ['cvFile' => 501] : null);
		$reader = $this->createMock(CvProfileReader::class);
		if ($status === null) {
			$reader->expects($this->never())->method('read');
		} else {
			$reader->expects($this->once())->method('read')
				->with($this->callback(static fn (array $application): bool => $application['id'] === 'app-1'), 'hr-demo')
				->willReturn(['status' => $status, 'message' => 'reason ' . $status, 'profile' => []]);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ApplicationCvController(request: $this->createMock(IRequest::class), rbac: $rbac, cvReader: $reader, userSession: $session);
	}//end controller()

	/**
	 * An application the caller cannot read is not found, and no CV is read.
	 *
	 * @return void
	 */
	public function testAnUnreadableApplicationIsNotFound(): void {
		$this->assertSame(404, $this->controller(readable: false, status: null)->readCv('app-1')->getStatus());
	}//end testAnUnreadableApplicationIsNotFound()

	/**
	 * Each outcome has its status; the reason is in the body.
	 *
	 * @return void
	 */
	public function testEachOutcomeHasItsStatus(): void {
		foreach (['filled' => 200, 'forbidden' => 403, 'kept' => 422, 'skipped-no-filinq' => 422, 'unreadable' => 422] as $status => $code) {
			$response = $this->controller(readable: true, status: $status)->readCv('app-1');

			$this->assertSame($code, $response->getStatus(), $status);
			$this->assertSame('reason ' . $status, $response->getData()['message']);
			$this->assertSame('app-1', $response->getData()['applicationId']);
		}
	}//end testEachOutcomeHasItsStatus()
}//end class
