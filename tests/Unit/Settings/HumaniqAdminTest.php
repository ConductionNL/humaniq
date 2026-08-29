<?php

/**
 * Tests for the delegated-admin marker guarding the setup wizard's endpoints.
 *
 * WHY THIS EXISTS. `HumaniqAdmin` holds no logic, so a test that only
 * instantiated it would assert that PHP can construct a class. The thing worth
 * protecting is the AUTH POSTURE it encodes, and that posture lives in two
 * places which can drift apart independently:
 *
 *   1. `SetupController::status()` and `runAction()` must actually CARRY
 *      `#[AuthorizedAdminSetting(...)]`. Deleting an attribute is a one-line
 *      change that no behavioural test notices — the endpoints keep working,
 *      for everyone, which is precisely the regression.
 *   2. The class the attribute names must grant NOTHING. An empty
 *      `getAuthorizedAppConfig()` is what keeps these endpoints full-admin-only;
 *      adding a single entry silently opens them to a group-restricted
 *      sub-admin, and again nothing else in the suite would fail.
 *
 * Both are asserted by REFLECTION over the real controller rather than against
 * a copy of the attribute list, so the test reads the same source the framework
 * does. A hand-maintained list of "endpoints that should be protected" would
 * pass while pointing at a method that no longer exists.
 *
 * The OCP types used here resolve to `vendor/nextcloud/ocp` via the bootstrap,
 * not to anything under `tests/stubs/` — neither `IDelegatedSettings` nor
 * `AuthorizedAdminSetting` is stubbed, so these assertions run against the real
 * contract rather than a local copy of it.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Settings
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Controller\SetupController;
use OCA\Humaniq\Settings\HumaniqAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Auth-posture tests for HumaniqAdmin and the endpoints that name it.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Settings
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 */
class HumaniqAdminTest extends TestCase {
	/**
	 * The setup endpoints whose access this marker is responsible for.
	 *
	 * @var string[]
	 */
	private const GUARDED_ENDPOINTS = ['status', 'runAction'];

	/**
	 * The marker must satisfy the attribute's own type constraint.
	 *
	 * `AuthorizedAdminSetting` takes a `class-string<IDelegatedSettings>`, so a
	 * change that dropped the interface would make the attribute unresolvable
	 * at runtime rather than fail at the point of edit.
	 *
	 * @return void
	 */
	public function testMarkerImplementsDelegatedSettings(): void {
		$this->assertInstanceOf(
			IDelegatedSettings::class,
			new HumaniqAdmin(),
			'HumaniqAdmin must implement IDelegatedSettings or AuthorizedAdminSetting cannot name it.',
		);
	}

	/**
	 * The marker must grant no delegated config access at all.
	 *
	 * This is the auth-critical assertion. An empty map is what keeps every
	 * endpoint scoped to this class full-admin-only; any entry here hands a
	 * group-restricted sub-admin the setup wizard.
	 *
	 * @return void
	 */
	public function testMarkerGrantsNothingToDelegatedAdmins(): void {
		$this->assertSame(
			[],
			(new HumaniqAdmin())->getAuthorizedAppConfig(),
			'A non-empty grant map opens the setup endpoints to group-restricted sub-admins.',
		);
	}

	/**
	 * The section keeps its own default name.
	 *
	 * @return void
	 */
	public function testMarkerDefersItsName(): void {
		$this->assertNull((new HumaniqAdmin())->getName());
	}

	/**
	 * Every guarded setup endpoint still carries the attribute, naming this class.
	 *
	 * Read off the controller by reflection: this fails the moment an attribute
	 * is removed, renamed, or repointed at a class that grants something.
	 *
	 * @return void
	 */
	public function testGuardedSetupEndpointsDeclareTheMarker(): void {
		$controller = new ReflectionClass(SetupController::class);

		foreach (self::GUARDED_ENDPOINTS as $endpoint) {
			$this->assertTrue(
				$controller->hasMethod($endpoint),
				sprintf('SetupController::%s() no longer exists — update this test with the endpoint.', $endpoint),
			);

			$attributes = $controller->getMethod($endpoint)->getAttributes(AuthorizedAdminSetting::class);

			$this->assertCount(
				1,
				$attributes,
				sprintf('SetupController::%s() must carry exactly one #[AuthorizedAdminSetting].', $endpoint),
			);

			$this->assertSame(
				[HumaniqAdmin::class],
				$attributes[0]->getArguments(),
				sprintf('SetupController::%s() must name HumaniqAdmin, whose grant map is empty.', $endpoint),
			);
		}
	}
}
