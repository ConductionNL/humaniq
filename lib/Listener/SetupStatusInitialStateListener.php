<?php

/**
 * Carries the setup status in an administrator's humaniq page.
 *
 * nextcloud-vue's CnAppRoot reads the app's setup status before it renders a
 * page. Asked over HTTP, `GET /api/setup/status` is one more Nextcloud request
 * on every page load. A page that carries the same document as initial state
 * `setup_status` (nextcloud-vue#2378) needs no such request.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-page-carries-the-setup-status-for-an-administrator-req-fts-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Controller\SetupController;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts SetupController::status() into humaniq's page as `setup_status`, for an administrator only.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-page-carries-the-setup-status-for-an-administrator-req-fts-008
 */
class SetupStatusInitialStateListener implements IEventListener {

	/**
	 * The template of humaniq's app page (PageController::index()).
	 *
	 * @var string
	 */
	public const PAGE_TEMPLATE = 'index';

	/**
	 * Constructor.
	 *
	 * The event fires for every app's pages, so nothing that reaches
	 * OpenRegister is injected: SetupController is resolved only for
	 * humaniq's own page, and an instance without OpenRegister still renders
	 * it (ADR-083 rule 3).
	 *
	 * @param IUserSession       $userSession  The caller.
	 * @param IGroupManager      $groupManager Tells whether the caller is an administrator.
	 * @param IInitialState      $initialState humaniq's initial state.
	 * @param ContainerInterface $container    Resolves SetupController (and the logger) only when needed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IInitialState $initialState,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Carry the setup status in humaniq's page for an administrator.
	 *
	 * Only for an administrator, and the document is exactly the one
	 * SetupController::status() answers, so nobody reads more than the
	 * endpoint gives them. Everyone else gets nothing carried and the shell
	 * asks the endpoint as before (a delegated settings admin is let in
	 * there; a user without access gets its 403). A failure carries nothing.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-page-carries-the-setup-status-for-an-administrator-req-fts-008
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false || $event->isLoggedIn() === false) {
			return;
		}

		$response = $event->getResponse();
		if ($response->getApp() !== Application::APP_ID || $response->getTemplateName() !== self::PAGE_TEMPLATE) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return;
		}

		try {
			$status = $this->container->get(SetupController::class)->status()->getData();
		} catch (\Throwable $e) {
			// Not an authorization decision: nothing is carried, and the shell
			// asks the endpoint itself, which applies its own access check.
			$this->container->get(LoggerInterface::class)->debug(
				'humaniq: setup status not carried; the shell asks the endpoint.',
				['exceptionClass' => $e::class]
			);
			return;
		}

		if (is_array($status) === true) {
			$this->initialState->provideInitialState('setup_status', $status);
		}

	}//end handle()
}//end class
