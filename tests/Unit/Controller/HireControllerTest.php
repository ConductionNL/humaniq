<?php

/**
 * The hire endpoints: resolve the application first (404), then HR or an
 * administrator (403), then 409 while matches are unresolved.
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\HireController;
use OCA\Humaniq\Service\HireMatchService;
use OCA\Humaniq\Service\HireService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Guards and status codes.
 */
class HireControllerTest extends TestCase {

	/**
	 * An unreadable application is 404 before any role is checked or anything is written.
	 *
	 * @return void
	 */
	public function testAnUnreadableApplicationIs404(): void {
		$hire = $this->createMock(HireService::class);
		$hire->expects(self::never())->method('hire');

		self::assertSame(404, $this->controller(readable: false, hr: true, hire: $hire)->hire('app-1')->getStatus());
		self::assertSame(404, $this->controller(readable: false, hr: true)->matches('app-1')->getStatus());
	}//end testAnUnreadableApplicationIs404()

	/**
	 * Someone outside HR cannot hire or see the matches.
	 *
	 * @return void
	 */
	public function testANonHrCallerIs403(): void {
		$hire = $this->createMock(HireService::class);
		$hire->expects(self::never())->method('hire');

		self::assertSame(403, $this->controller(readable: true, hr: false, hire: $hire)->hire('app-1')->getStatus());
		self::assertSame(403, $this->controller(readable: true, hr: false)->matches('app-1')->getStatus());
	}//end testANonHrCallerIs403()

	/**
	 * Unresolved matches answer 409 with the matches; created answers 201.
	 *
	 * @return void
	 */
	public function testMatchesAre409AndACreateIs201(): void {
		$hire = $this->createMock(HireService::class);
		$hire->method('hire')->willReturnOnConsecutiveCalls(
			['outcome' => 'matches', 'matches' => [['employeeId' => 'emp-smit', 'matchedOn' => 'bsn']]],
			['outcome' => 'created', 'employeeId' => 'emp-new', 'onboardingId' => 'case-new'],
			['outcome' => 'already-linked', 'employeeId' => 'emp-new'],
			['outcome' => 'invalid', 'message' => 'A start date is needed.'],
			['outcome' => 'not-hired', 'message' => 'Only a hired application.'],
		);
		$controller = $this->controller(readable: true, hr: true, hire: $hire);

		$conflict = $controller->hire('app-1');
		self::assertSame(409, $conflict->getStatus());
		self::assertSame('emp-smit', $conflict->getData()['matches'][0]['employeeId']);
		self::assertSame(201, $controller->hire('app-1')->getStatus());
		self::assertSame(200, $controller->hire('app-1')->getStatus());
		self::assertSame(400, $controller->hire('app-1')->getStatus());
		self::assertSame(409, $controller->hire('app-1')->getStatus());
	}//end testMatchesAre409AndACreateIs201()

	/**
	 * HR reads the proposal: the split name, the linked employee and the
	 * matches on the application's e-mail.
	 *
	 * @return void
	 */
	public function testHrReadsTheProposal(): void {
		$matcher = $this->createMock(HireMatchService::class);
		$matcher->expects(self::once())->method('matches')
			->with(['privateEmail' => 'sanne@example.org', 'lastName' => 'de Boer', 'bsn' => '', 'dateOfBirth' => ''])
			->willReturn([]);

		$data = $this->controller(readable: true, hr: true, matcher: $matcher)->matches('app-1')->getData();

		self::assertSame('Sanne', $data['proposal']['firstName']);
		self::assertSame('de Boer', $data['proposal']['lastName']);
		self::assertNull($data['employeeId']);
		self::assertSame([], $data['matches']);
	}//end testHrReadsTheProposal()

	/**
	 * The controller with stubbed collaborators.
	 *
	 * @param boolean               $readable Whether the application resolves.
	 * @param boolean               $hr       Whether the caller is HR.
	 * @param HireService|null      $hire     The hire service.
	 * @param HireMatchService|null $matcher  The match service.
	 *
	 * @return HireController
	 */
	private function controller(bool $readable, bool $hr, ?HireService $hire=null, ?HireMatchService $matcher=null): HireController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($readable ? ['id' => 'app-1', 'candidateName' => 'Sanne de Boer', 'email' => 'sanne@example.org', 'status' => 'aangenomen'] : null);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		return new HireController(
			$request,
			$rbac,
			$roles,
			($hire ?? $this->createMock(HireService::class)),
			($matcher ?? $this->createMock(HireMatchService::class)),
			$session,
		);
	}//end controller()

}//end class
