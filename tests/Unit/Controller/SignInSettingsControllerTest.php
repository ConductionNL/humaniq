<?php

/**
 * SignInSettingsController unit tests
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
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\SignInSettingsController;
use OCA\Humaniq\Service\SignInProviderNames;
use OCA\Humaniq\Service\SignInSettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The Inloggen section's endpoint.
 *
 * @covers \OCA\Humaniq\Controller\SignInSettingsController
 * @uses   \OCA\Humaniq\Service\SignInSettingsService
 * @uses   \OCA\Humaniq\Service\PolicyAppMissingException
 */
class SignInSettingsControllerTest extends TestCase {

	/**
	 * The stored `password_policy` values, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $policy = [];

	/**
	 * The controller over an in-memory app config.
	 *
	 * @param bool $policyApp Whether `password_policy` is enabled.
	 *
	 * @return SignInSettingsController
	 */
	private function controller(bool $policyApp = true): SignInSettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAllValues')->willReturnCallback(fn (): array => $this->policy);
		$appConfig->method('getValueType')->willThrowException(new AppConfigUnknownKeyException('unset'));
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->policy[$key] = $value;
				return true;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => ($app === 'password_policy' && $policyApp === true));

		$service = new SignInSettingsService($appConfig, $appManager, $this->createMock(SignInProviderNames::class));

		return new SignInSettingsController($this->createMock(IRequest::class), $service);
	}//end controller()

	/**
	 * A saved minimum length is read back as the policy app's value.
	 *
	 * @return void
	 */
	public function testASavedLengthIsReadBack(): void {
		$saved = $this->controller()->update(['minLength' => 12]);

		self::assertSame(200, $saved->getStatus());
		self::assertSame(['minLength' => 12], $this->policy);
		self::assertSame(12, $saved->getData()['passwordPolicy']['values']['minLength']);
		self::assertSame(12, $this->controller()->show()->getData()['passwordPolicy']['values']['minLength']);
	}//end testASavedLengthIsReadBack()

	/**
	 * An unknown key and a body that is not a set of named values are a 400.
	 *
	 * @return void
	 */
	public function testABadBodyIsRefused(): void {
		$unknown = $this->controller()->update(['passwordExpiry' => 90]);
		self::assertSame(400, $unknown->getStatus());
		self::assertSame('Unknown password policy setting: passwordExpiry.', $unknown->getData()['message']);

		foreach ([null, 'minLength', [12]] as $body) {
			$response = $this->controller()->update($body);
			self::assertSame(400, $response->getStatus());
			self::assertSame('Send the password policy values by name.', $response->getData()['message']);
		}

		self::assertSame([], $this->policy);
	}//end testABadBodyIsRefused()

	/**
	 * Without the policy app a save is a 409 and the read says the policy is not available.
	 *
	 * @return void
	 */
	public function testWithoutThePolicyAppASaveIsAConflict(): void {
		$response = $this->controller(policyApp: false)->update(['minLength' => 12]);

		self::assertSame(409, $response->getStatus());
		self::assertSame('The password policy needs the Nextcloud password policy app.', $response->getData()['message']);
		self::assertFalse($this->controller(policyApp: false)->show()->getData()['passwordPolicy']['available']);
		self::assertSame([], $this->policy);
	}//end testWithoutThePolicyAppASaveIsAConflict()

	/**
	 * Both methods are for full administrators: no attribute lets anyone else in.
	 *
	 * @return void
	 */
	public function testOnlyAdministratorsReachIt(): void {
		foreach (['show', 'update'] as $method) {
			$reflection = new ReflectionMethod(SignInSettingsController::class, $method);
			foreach ([NoAdminRequired::class, AuthorizedAdminSetting::class, PublicPage::class] as $attribute) {
				self::assertSame([], $reflection->getAttributes($attribute), $method.' carries '.$attribute);
			}
		}
	}//end testOnlyAdministratorsReachIt()

}//end class
