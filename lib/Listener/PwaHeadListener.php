<?php

/**
 * Links the web manifest from the head of humaniq's own pages.
 *
 * @category  Listener
 * @package   OCA\Humaniq\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-mijn-hr-shall-be-installable-on-a-phones-home-screen-req-mob-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Adds `<link rel="manifest">` to the page head when the rendered template is
 * humaniq's, so a phone browser offers to install `Mijn HR`. Other apps'
 * pages are left alone: the manifest's scope is humaniq's alone.
 *
 * @template-implements IEventListener<Event>
 */
class PwaHeadListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Builds the manifest URL.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Link the manifest on a humaniq page.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-mijn-hr-shall-be-installable-on-a-phones-home-screen-req-mob-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false) {
			return;
		}

		if ($event->getResponse()->getApp() !== Application::APP_ID) {
			return;
		}

		$this->addHeader(
			tag: 'link',
			attributes: [
				'rel' => 'manifest',
				'href' => $this->urlGenerator->linkToRoute(routeName: Application::APP_ID.'.pwa.manifest'),
			]
		);
	}//end handle()

	/**
	 * Add a tag to the page head.
	 *
	 * @param string                $tag        The tag name.
	 * @param array<string, string> $attributes The tag's attributes.
	 *
	 * @return void
	 */
	protected function addHeader(string $tag, array $attributes): void {
		Util::addHeader($tag, $attributes);
	}//end addHeader()
}//end class
