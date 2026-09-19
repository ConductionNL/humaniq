<?php

/**
 * TimeEntryController unit tests
 *
 * The three timer endpoints, and specifically the two properties the endpoints
 * exist to hold: every request resolves from the CALLER and never from an id in
 * the request, and a second start is refused with the object the running timer
 * belongs to rather than with a bare failure.
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\TimeEntryController;
use OCA\Humaniq\Listener\HoursWriteRefusedException;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RunningTimerService;
use OCA\Humaniq\Service\TimeEstimateService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Status codes, refusals and the caller-scoped resolution.
 */
class TimeEntryControllerTest extends TestCase {

	/**
	 * The timer service, mocked.
	 *
	 * @var RunningTimerService&MockObject
	 */
	private RunningTimerService $timers;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->timers = $this->createMock(RunningTimerService::class);
	}//end setUp()

	/**
	 * Build the subject with or without a signed-in caller.
	 *
	 * @param string|null $uid The caller's user id, or null for no session.
	 *
	 * @return TimeEntryController The subject.
	 */
	private function controller(?string $uid): TimeEntryController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new TimeEntryController(
			$this->createMock(IRequest::class),
			$session,
			$this->timers,
			new NullLogger(),
			$this->createMock(HoursRegisterGateway::class),
			$this->createMock(TimeEstimateService::class)
		);
	}//end controller()

	/**
	 * With nothing running, the answer is a status and not an error.
	 *
	 * @return void
	 */
	public function testTimerAnswersNoneWhenNothingIsRunning(): void {
		$this->timers->method('running')->willReturn(null);

		$response = $this->controller('alice')->timer();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(RunningTimerService::STATUS_NONE, $response->getData()['status']);
	}//end testTimerAnswersNoneWhenNothingIsRunning()

	/**
	 * The running entry is resolved with the CALLER's id and no other.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	public function testTimerResolvesWithTheCallersOwnId(): void {
		$this->timers->expects($this->once())
			->method('running')
			->with('alice')
			->willReturn(['id' => 'entry-uuid']);

		$response = $this->controller('alice')->timer();

		$this->assertSame(RunningTimerService::STATUS_RUNNING, $response->getData()['status']);
		$this->assertSame('entry-uuid', $response->getData()['entry']['id']);
	}//end testTimerResolvesWithTheCallersOwnId()

	/**
	 * No session, no timer. The endpoints are NoAdminRequired, not public.
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesWithoutASession(): void {
		$this->timers->expects($this->never())->method('running');
		$this->timers->expects($this->never())->method('start');
		$this->timers->expects($this->never())->method('stop');

		$controller = $this->controller(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->timer()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->startTimer('dossiq:case', 'x')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->stopTimer()->getStatus());
	}//end testEveryEndpointRefusesWithoutASession()

	/**
	 * A second start answers 409 and carries the entry already running, so the
	 * caller can name the object being timed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	public function testASecondStartAnswersConflictAndNamesTheRunningObject(): void {
		$this->timers->method('start')->willReturn(
			[
				'status' => RunningTimerService::STATUS_ALREADY_RUNNING,
				'entry' => ['domainObjectRef' => 'other-case'],
			]
		);

		$response = $this->controller('alice')->startTimer('dossiq:case', 'this-case');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('other-case', $response->getData()['entry']['domainObjectRef']);
	}//end testASecondStartAnswersConflictAndNamesTheRunningObject()

	/**
	 * A start missing half the object reference is a bad request, not a
	 * conflict and not a silent success.
	 *
	 * @return void
	 */
	public function testAStartWithoutAnObjectReferenceIsABadRequest(): void {
		$this->timers->method('start')->willReturn(
			['status' => RunningTimerService::STATUS_INVALID, 'error' => 'nope']
		);

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller('alice')->startTimer('dossiq:case', null)->getStatus()
		);
	}//end testAStartWithoutAnObjectReferenceIsABadRequest()

	/**
	 * A successful start is a plain 200 carrying the created entry.
	 *
	 * @return void
	 */
	public function testAStartAnswersTheCreatedEntry(): void {
		$this->timers->expects($this->once())
			->method('start')
			->with('alice', 'dossiq:case', 'case-uuid')
			->willReturn(['status' => RunningTimerService::STATUS_RUNNING, 'entry' => ['id' => 'new']]);

		$response = $this->controller('alice')->startTimer('dossiq:case', 'case-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('new', $response->getData()['entry']['id']);
	}//end testAStartAnswersTheCreatedEntry()

	/**
	 * Stopping resolves from the caller and takes no arguments at all, so
	 * stopping someone else's timer is not a request that can be phrased.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	public function testStopResolvesFromTheCallerAlone(): void {
		$this->timers->expects($this->once())
			->method('stop')
			->with('alice')
			->willReturn(['status' => RunningTimerService::STATUS_STOPPED, 'entry' => ['hours' => 2.0]]);

		$response = $this->controller('alice')->stopTimer();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2.0, $response->getData()['entry']['hours']);
	}//end testStopResolvesFromTheCallerAlone()

	/**
	 * Stopping nothing is a 404 with a sentence a person can act on.
	 *
	 * @return void
	 */
	public function testStoppingNothingAnswersNotFound(): void {
		$this->timers->method('stop')->willReturn(['status' => RunningTimerService::STATUS_NONE]);

		$response = $this->controller('alice')->stopTimer();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertNotEmpty($response->getData()['error']);
	}//end testStoppingNothingAnswersNotFound()

	/**
	 * A deliberate refusal keeps its own message. The sentence is written for a
	 * person to read and is the entire point of raising the exception.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsMessage(): void {
		$this->timers->method('running')->willThrowException(
			new HoursWriteRefusedException('Er is geen medewerker gekoppeld aan je account.')
		);

		$response = $this->controller('alice')->timer();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Er is geen medewerker gekoppeld aan je account.', $response->getData()['error']);
	}//end testARefusalKeepsItsMessage()

}//end class
