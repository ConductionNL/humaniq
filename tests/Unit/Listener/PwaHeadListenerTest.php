<?php

/**
 * Unit tests for the manifest link in the page head.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\PwaHeadListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Only humaniq's own pages link the web manifest.
 *
 * @covers \OCA\Humaniq\Listener\PwaHeadListener
 */
class PwaHeadListenerTest extends TestCase {

	/**
	 * A listener that records the head tags instead of emitting them.
	 *
	 * @return PwaHeadListener&object{added: array<int, array{0: string, 1: array<string, string>}>}
	 */
	private function listener(): PwaHeadListener {
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('humaniq.pwa.manifest')->willReturn('/apps/humaniq/manifest.webmanifest');

		return new class($urlGenerator) extends PwaHeadListener {
			/** @var array<int, array{0: string, 1: array<string, string>}> */
			public array $added = [];

			/**
			 * Record the tag.
			 *
			 * @param string                $tag        The tag name.
			 * @param array<string, string> $attributes The tag's attributes.
			 *
			 * @return void
			 */
			protected function addHeader(string $tag, array $attributes): void {
				$this->added[] = [$tag, $attributes];
			}
		};
	}//end listener()

	/**
	 * A rendered page of the given app.
	 *
	 * @param string $app The app id.
	 *
	 * @return BeforeTemplateRenderedEvent The event.
	 */
	private function page(string $app): BeforeTemplateRenderedEvent {
		$response = $this->createMock(originalClassName: TemplateResponse::class);
		$response->method('getApp')->willReturn($app);

		$event = $this->createMock(originalClassName: BeforeTemplateRenderedEvent::class);
		$event->method('getResponse')->willReturn($response);

		return $event;
	}//end page()

	/**
	 * A humaniq page links the manifest.
	 *
	 * @return void
	 */
	public function testAHumaniqPageLinksTheManifest(): void {
		$listener = $this->listener();
		$listener->handle($this->page(app: 'humaniq'));

		self::assertSame(
			[['link', ['rel' => 'manifest', 'href' => '/apps/humaniq/manifest.webmanifest']]],
			$listener->added
		);
	}//end testAHumaniqPageLinksTheManifest()

	/**
	 * Another app's page is left alone.
	 *
	 * @return void
	 */
	public function testAnotherAppsPageIsLeftAlone(): void {
		$listener = $this->listener();
		$listener->handle($this->page(app: 'files'));

		self::assertSame([], $listener->added);
	}//end testAnotherAppsPageIsLeftAlone()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$listener = $this->listener();
		$listener->handle(new Event());

		self::assertSame([], $listener->added);
	}//end testAnotherEventIsIgnored()
}//end class
