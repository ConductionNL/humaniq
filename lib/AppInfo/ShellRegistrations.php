<?php
/**
 * Humaniq ShellRegistrations.
 *
 * What the app shell serves beside its pages: the web-manifest link in the
 * page head, and the store controller behind the Marketplace page and the
 * setup wizard's Sjablonen step.
 *
 * @category AppInfo
 * @package  OCA\Humaniq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-store-page-shall-install-published-configuration-sets-through-openregister-req-fts-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\AppInfo;

use OCA\Humaniq\Listener\PwaHeadListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Server;

/**
 * Registers the head link and the store controller binding.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-store-page-shall-install-published-configuration-sets-through-openregister-req-fts-006
 */
final class ShellRegistrations {

	/**
	 * OpenRegister's AppHost bootstrap, named by string so this file loads without it.
	 *
	 * @var string
	 */
	private const APPHOST_BOOTSTRAP = 'OCA\\OpenRegister\\AppHost\\Bootstrap';

	/**
	 * Register the head link and bind the store controller.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-store-page-shall-install-published-configuration-sets-through-openregister-req-fts-006
	 */
	public function register(IRegistrationContext $context): void {
		// self-service-mobile (design.md D1): link the web manifest from the
		// head of humaniq's own pages, so a phone browser offers to install Mijn HR.
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, PwaHeadListener::class);

		$this->bindStoreController(context: $context);
	}//end register()

	/**
	 * Bind OpenRegister's store controller as this app's StoreController.
	 *
	 * configuration-store D1: the Marketplace page and the Sjablonen step read
	 * and install configuration sets through `/api/store/items`, which
	 * OpenRegister serves; humaniq holds no store code. humaniq registers
	 * before OpenRegister (apps register in sorted order), so OpenRegister's
	 * autoloader is registered first (the ADR-040 prelude). Without
	 * OpenRegister nothing is bound.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Server::get, OC_App::registerAutoloading and Bootstrap::aliasStoreController are the published one-call contracts for this binding.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-store-page-shall-install-published-configuration-sets-through-openregister-req-fts-006
	 */
	private function bindStoreController(IRegistrationContext $context): void {
		try {
			$orPath = Server::get(IAppManager::class)->getAppPath('openregister');
			\OC_App::registerAutoloading('openregister', $orPath);
		} catch (\Throwable) {
			// OpenRegister absent or disabled: the store stays unbound.
			return;
		}

		if (class_exists(self::APPHOST_BOOTSTRAP) === true) {
			\OCA\OpenRegister\AppHost\Bootstrap::aliasStoreController(context: $context, appId: Application::APP_ID, controllerNs: 'OCA\\Humaniq\\Controller');
		}
	}//end bindStoreController()
}//end class
