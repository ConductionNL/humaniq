<?php

/**
 * Unit tests for SetupStatusInitialStateListener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-page-carries-the-setup-status-for-an-administrator-req-fts-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Controller\SetupController;
use OCA\Humaniq\Listener\SetupStatusInitialStateListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What humaniq's page carries as `setup_status`, and for whom.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-page-carries-the-setup-status-for-an-administrator-req-fts-008
 */
class SetupStatusInitialStateListenerTest extends TestCase {

	/**
	 * Recorded `provideInitialState` calls, `key => value`.
	 *
	 * @var array<string, mixed>
	 */
	private array $carried = [];

	/**
	 * An administrator's humaniq page carries exactly what the endpoint answers.
	 *
	 * @return void
	 */
	public function testAnAdministratorsPageCarriesTheSetupStatus(): void {
		$document = ['version' => 1, 'completed' => true, 'steps' => ['welcome' => ['done' => true]]];
		$setup    = $this->createMock(SetupController::class);
		$setup->expects($this->once())->method('status')->willReturn(new JSONResponse(data: $document));

		$this->listener(isAdmin: true, setup: $setup)->handle($this->pageEvent());

		$this->assertSame(['setup_status' => $document], $this->carried);
	}//end testAnAdministratorsPageCarriesTheSetupStatus()

	/**
	 * Anyone else gets nothing carried, and the endpoint is not even resolved.
	 *
	 * @return void
	 */
	public function testANonAdministratorsPageCarriesNothing(): void {
		$setup = $this->createMock(SetupController::class);
		$setup->expects($this->never())->method('status');

		$this->listener(isAdmin: false, setup: $setup)->handle($this->pageEvent());

		$this->assertSame([], $this->carried);
	}//end testANonAdministratorsPageCarriesNothing()

	/**
	 * Another app's page, another humaniq template, a logged-out render or
	 * another event carry nothing and resolve nothing.
	 *
	 * @return void
	 */
	public function testOnlyHumaniqsOwnPageCarriesIt(): void {
		$setup = $this->createMock(SetupController::class);
		$setup->expects($this->never())->method('status');
		$listener = $this->listener(isAdmin: true, setup: $setup);

		$listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('files', 'index')));
		$listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('humaniq', 'settings/admin')));
		$listener->handle(new BeforeTemplateRenderedEvent(false, new TemplateResponse('humaniq', 'index')));
		$listener->handle(new Event());

		$this->assertSame([], $this->carried);
	}//end testOnlyHumaniqsOwnPageCarriesIt()

	/**
	 * A status that cannot be built (OpenRegister missing or broken) carries nothing.
	 *
	 * @return void
	 */
	public function testAFailingStatusCarriesNothing(): void {
		$setup = $this->createMock(SetupController::class);
		$setup->method('status')->willThrowException(new \RuntimeException('OpenRegister is not installed'));

		$this->listener(isAdmin: true, setup: $setup)->handle($this->pageEvent());

		$this->assertSame([], $this->carried);
	}//end testAFailingStatusCarriesNothing()

	/**
	 * Humaniq's page render for a logged-in caller.
	 *
	 * @return BeforeTemplateRenderedEvent
	 */
	private function pageEvent(): BeforeTemplateRenderedEvent {
		return new BeforeTemplateRenderedEvent(true, new TemplateResponse('humaniq', SetupStatusInitialStateListener::PAGE_TEMPLATE));
	}//end pageEvent()

	/**
	 * The listener with a signed-in user.
	 *
	 * @param bool            $isAdmin Whether that user is an administrator.
	 * @param SetupController $setup   What the container hands out for SetupController.
	 *
	 * @return SetupStatusInitialStateListener
	 */
	private function listener(bool $isAdmin, SetupController $setup): SetupStatusInitialStateListener {
		$this->carried = [];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('someone')->willReturn($isAdmin);

		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value): void {
				$this->carried[$key] = $value;
			}
		);

		$logger    = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === SetupController::class ? $setup : $logger
		);

		return new SetupStatusInitialStateListener($userSession, $groupManager, $initialState, $container);
	}//end listener()
}//end class
