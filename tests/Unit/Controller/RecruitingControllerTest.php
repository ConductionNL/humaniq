<?php

/**
 * The two recruiting reads: a vacancy's ranked matches and an
 * application's average score per criterion.
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
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-001
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\RecruitingController;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\VacancyMatchService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Resolve first, then HR or an administrator.
 */
class RecruitingControllerTest extends TestCase {

	/**
	 * An unreadable vacancy is 404 before any role is checked.
	 *
	 * @return void
	 */
	public function testAnUnreadableVacancyIs404(): void {
		$matcher = $this->createMock(VacancyMatchService::class);
		$matcher->expects(self::never())->method('matchesFor');

		self::assertSame(404, $this->controller(readable: false, hr: true, matcher: $matcher)->matches('vac-1')->getStatus());
	}//end testAnUnreadableVacancyIs404()

	/**
	 * Someone outside HR does not see who fits a vacancy.
	 *
	 * @return void
	 */
	public function testANonHrCallerIs403(): void {
		$matcher = $this->createMock(VacancyMatchService::class);
		$matcher->expects(self::never())->method('matchesFor');

		self::assertSame(403, $this->controller(readable: true, hr: false, matcher: $matcher)->matches('vac-1')->getStatus());
		self::assertSame(403, $this->controller(readable: true, hr: false)->evaluationSummary('app-1')->getStatus());
	}//end testANonHrCallerIs403()

	/**
	 * HR reads the ranked list.
	 *
	 * @return void
	 */
	public function testHrReadsTheMatches(): void {
		$matcher = $this->createMock(VacancyMatchService::class);
		$matcher->expects(self::once())->method('matchesFor')->with('vac-1')->willReturn([['name' => 'Petra Jansen', 'total' => 20]]);

		$response = $this->controller(readable: true, hr: true, matcher: $matcher)->matches('vac-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame('Petra Jansen', $response->getData()['matches'][0]['name']);
	}//end testHrReadsTheMatches()

	/**
	 * Two interviewers who scored 4 and 2 average 3 on that criterion.
	 *
	 * @return void
	 */
	public function testTheAverageOfTwoInterviewers(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->with('CandidateEvaluation', ['applicationId' => 'app-1'])->willReturn([
			['evaluatorUserId' => 'manager.one', 'scores' => [['criterion' => 'Payroll knowledge', 'score' => 4], ['criterion' => 'Communication', 'score' => 5]]],
			['evaluatorUserId' => 'hr.user', 'scores' => [['criterion' => 'Payroll knowledge', 'score' => 2]]],
		]);

		$data = $this->controller(readable: true, hr: true, gateway: $gateway)->evaluationSummary('app-1')->getData();

		self::assertSame(2, $data['evaluations']);
		self::assertSame(['criterion' => 'Payroll knowledge', 'average' => 3.0, 'count' => 2], $data['criteria'][0]);
		self::assertSame(['criterion' => 'Communication', 'average' => 5.0, 'count' => 1], $data['criteria'][1]);
	}//end testTheAverageOfTwoInterviewers()

	/**
	 * The controller with stubbed collaborators.
	 *
	 * @param boolean                   $readable Whether the object resolves.
	 * @param boolean                   $hr       Whether the caller is HR.
	 * @param VacancyMatchService|null  $matcher  The matcher.
	 * @param HoursRegisterGateway|null $gateway  The gateway.
	 *
	 * @return RecruitingController
	 */
	private function controller(bool $readable, bool $hr, ?VacancyMatchService $matcher=null, ?HoursRegisterGateway $gateway=null): RecruitingController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($readable ? ['id' => 'x'] : null);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RecruitingController(
			request: $this->createMock(IRequest::class),
			rbac: $rbac,
			roles: $roles,
			matcher: ($matcher ?? $this->createMock(VacancyMatchService::class)),
			gateway: ($gateway ?? $this->createMock(HoursRegisterGateway::class)),
			userSession: $session
		);
	}//end controller()

}//end class
