<?php

/**
 * SurveyControllerTest
 *
 * Opening a survey and reading its results is for HR or an administrator;
 * answering needs a logged-in caller and passes the service's status on.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\SurveyController;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SurveyService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
class SurveyControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var SurveyService&MockObject
	 */
	private SurveyService&MockObject $service;

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(SurveyService::class);
	}//end setUp()

	/**
	 * Only HR opens a survey; the service's status is passed on.
	 *
	 * @return void
	 */
	public function testOnlyHrOpensASurvey(): void {
		$this->service->expects(self::once())->method('open')->with('srv-1', self::isType('string'))->willReturn(['status' => 200, 'message' => null, 'invited' => 9, 'withoutAccount' => 1]);

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller('anna')->open('srv-1')->getStatus());
		$response = $this->controller('hr.user')->open('srv-1');
		self::assertSame([Http::STATUS_OK, 9], [$response->getStatus(), $response->getData()['invited']]);
	}//end testOnlyHrOpensASurvey()

	/**
	 * Only HR reads the results; an unknown survey is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	public function testOnlyHrReadsTheResults(): void {
		$this->service->method('results')->willReturnCallback(static fn (string $surveyId): ?array => ($surveyId === 'srv-1' ? ['responses' => 14] : null));

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller('anna')->results('srv-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(null)->results('srv-1')->getStatus());
		self::assertSame(14, $this->controller('hr.user')->results('srv-1')->getData()['responses']);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller('hr.user')->results('srv-x')->getStatus());
	}//end testOnlyHrReadsTheResults()

	/**
	 * Answering needs a caller and passes the service's status on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function testAnsweringPassesTheServiceStatusOn(): void {
		$this->service->method('respond')->with('srv-1', 'anna', ['werkplezier' => 4], self::isType('string'))->willReturnOnConsecutiveCalls(
			['status' => 201, 'message' => null],
			['status' => 409, 'message' => 'You already answered this survey.']
		);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->respond('srv-1', ['werkplezier' => 4])->getStatus());
		self::assertSame(Http::STATUS_CREATED, $this->controller('anna')->respond('srv-1', ['werkplezier' => 4])->getStatus());
		$again = $this->controller('anna')->respond('srv-1', ['werkplezier' => 4]);
		self::assertSame([Http::STATUS_CONFLICT, 'You already answered this survey.'], [$again->getStatus(), $again->getData()['message']]);
	}//end testAnsweringPassesTheServiceStatusOn()

	/**
	 * The controller for a caller.
	 *
	 * @param string|null $uid The caller, null when logged out.
	 *
	 * @return SurveyController
	 */
	private function controller(?string $uid): SurveyController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => $who === 'hr.user' && $group === HumaniqRoles::HR_GROUP);

		return new SurveyController(
			request: $this->createMock(IRequest::class),
			surveys: $this->service,
			roles: new HumaniqRoles($groups),
			userSession: $session
		);
	}//end controller()

}//end class
