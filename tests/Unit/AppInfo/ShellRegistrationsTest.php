<?php

/**
 * Unit tests for ShellRegistrations (configuration-store, REQ-FTS-006).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-store-page-shall-install-published-configuration-sets-through-openregister-req-fts-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\AppInfo;

use OCA\Humaniq\AppInfo\ShellRegistrations;
use OCA\Humaniq\Listener\PwaHeadListener;
use OCA\Humaniq\Listener\SetupStatusInitialStateListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use PHPUnit\Framework\TestCase;

/**
 * The head link and the store binding.
 *
 * @covers \OCA\Humaniq\AppInfo\ShellRegistrations
 */
final class ShellRegistrationsTest extends TestCase {

	/**
	 * The head link and the setup-status carry (REQ-FTS-008) are registered,
	 * and without a reachable OpenRegister the store stays unbound instead of
	 * aborting the app's registration.
	 *
	 * @return void
	 */
	public function testRegistersTheHeadLinkAndSurvivesAMissingOpenRegister(): void {
		$listeners = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$listeners): void {
				$listeners[] = [$event, $listener];
			}
		);
		$context->expects($this->never())->method('registerService');

		(new ShellRegistrations())->register($context);

		self::assertSame(
			[
				[BeforeTemplateRenderedEvent::class, PwaHeadListener::class],
				[BeforeTemplateRenderedEvent::class, SetupStatusInitialStateListener::class],
			],
			$listeners
		);
	}
}
