<?php

/**
 * SignInSettingsService unit tests
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\PolicyAppMissingException;
use OCA\Humaniq\Service\SignInProviderNames;
use OCA\Humaniq\Service\SignInSettingsService;
use OCP\App\IAppManager;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reading and saving Nextcloud's own sign-in settings.
 *
 * @covers \OCA\Humaniq\Service\SignInSettingsService
 * @uses   \OCA\Humaniq\Service\PolicyAppMissingException
 */
class SignInSettingsServiceTest extends TestCase {

	/**
	 * The `password_policy` config: key => [type, value].
	 *
	 * @var array<string, array{0: int, 1: mixed}>
	 */
	private array $policy = [];

	/**
	 * The writes made: [setter, app, key, value].
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: mixed}>
	 */
	private array $writes = [];

	/**
	 * The service over an in-memory app config.
	 *
	 * @param list<string>               $enabled   The enabled apps.
	 * @param array<string, list<string>> $providers Provider names by sign-in app.
	 *
	 * @return SignInSettingsService
	 */
	private function service(array $enabled = ['password_policy'], array $providers = []): SignInSettingsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAllValues')->willReturnCallback(
			fn (string $app): array => array_map(static fn (array $entry): mixed => $entry[1], ($app === 'password_policy') ? $this->policy : [])
		);
		$appConfig->method('getValueType')->willReturnCallback(
			function (string $app, string $key): int {
				if (isset($this->policy[$key]) === false) {
					throw new AppConfigUnknownKeyException('unknown '.$key);
				}

				return $this->policy[$key][0];
			}
		);
		foreach (['setValueString' => IAppConfig::VALUE_STRING, 'setValueInt' => IAppConfig::VALUE_INT, 'setValueBool' => IAppConfig::VALUE_BOOL] as $setter => $type) {
			$appConfig->method($setter)->willReturnCallback(
				function (string $app, string $key, mixed $value) use ($setter, $type): bool {
					$this->writes[] = [$setter, $app, $key, $value];
					$this->policy[$key] = [($this->policy[$key][0] ?? $type), $value];
					return true;
				}
			);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => in_array($app, $enabled, true));

		$names = $this->createMock(SignInProviderNames::class);
		$names->method('namesFor')->willReturnCallback(static fn (string $app): array => ($providers[$app] ?? []));

		return new SignInSettingsService($appConfig, $appManager, $names);
	}//end service()

	/**
	 * Unset keys read as Nextcloud's defaults.
	 *
	 * @return void
	 */
	public function testUnsetKeysReadAsNextcloudsDefaults(): void {
		$policy = $this->service()->read()['passwordPolicy'];

		self::assertTrue($policy['available']);
		self::assertSame(
			[
				'minLength' => 10,
				'enforceUpperLowerCase' => false,
				'enforceNumericCharacters' => false,
				'enforceSpecialCharacters' => false,
				'enforceHaveIBeenPwned' => true,
				'maximumLoginAttempts' => 0,
			],
			$policy['values']
		);
	}//end testUnsetKeysReadAsNextcloudsDefaults()

	/**
	 * Stored values are read in their key's type, whether stored as strings or typed.
	 *
	 * @return void
	 */
	public function testStoredValuesAreReadInTheirType(): void {
		$this->policy = [
			'minLength' => [IAppConfig::VALUE_MIXED, '12'],
			'enforceUpperLowerCase' => [IAppConfig::VALUE_MIXED, '1'],
			'enforceNumericCharacters' => [IAppConfig::VALUE_BOOL, true],
			'enforceHaveIBeenPwned' => [IAppConfig::VALUE_MIXED, '0'],
			'maximumLoginAttempts' => [IAppConfig::VALUE_INT, 5],
			'enforceSpecialCharacters' => [IAppConfig::VALUE_MIXED, 'garbage'],
		];

		$values = $this->service()->read()['passwordPolicy']['values'];

		self::assertSame(12, $values['minLength']);
		self::assertTrue($values['enforceUpperLowerCase']);
		self::assertTrue($values['enforceNumericCharacters']);
		self::assertFalse($values['enforceHaveIBeenPwned']);
		self::assertSame(5, $values['maximumLoginAttempts']);
		self::assertFalse($values['enforceSpecialCharacters']);
	}//end testStoredValuesAreReadInTheirType()

	/**
	 * A minimum length that is not a number reads as the default.
	 *
	 * @return void
	 */
	public function testANonNumericLengthReadsAsTheDefault(): void {
		$this->policy = ['minLength' => [IAppConfig::VALUE_MIXED, 'ten']];

		self::assertSame(10, $this->service()->read()['passwordPolicy']['values']['minLength']);
	}//end testANonNumericLengthReadsAsTheDefault()

	/**
	 * A save writes in the policy app's config, in the type each key is already stored in.
	 *
	 * @return void
	 */
	public function testASaveWritesThePolicyAppsOwnConfig(): void {
		$this->policy = [
			'minLength' => [IAppConfig::VALUE_MIXED, '10'],
			'maximumLoginAttempts' => [IAppConfig::VALUE_INT, 0],
		];

		$read = $this->service()->save(['minLength' => 12, 'maximumLoginAttempts' => 5, 'enforceSpecialCharacters' => true]);

		self::assertSame(
			[
				['setValueString', 'password_policy', 'minLength', '12'],
				['setValueInt', 'password_policy', 'maximumLoginAttempts', 5],
				['setValueBool', 'password_policy', 'enforceSpecialCharacters', true],
			],
			$this->writes
		);
		self::assertSame(12, $read['passwordPolicy']['values']['minLength']);
		self::assertSame(5, $read['passwordPolicy']['values']['maximumLoginAttempts']);
		self::assertTrue($read['passwordPolicy']['values']['enforceSpecialCharacters']);
	}//end testASaveWritesThePolicyAppsOwnConfig()

	/**
	 * A switch kept as a string is written as `1` or `0`.
	 *
	 * @return void
	 */
	public function testASwitchKeptAsAStringIsWrittenAsOneOrZero(): void {
		$this->policy = ['enforceHaveIBeenPwned' => [IAppConfig::VALUE_STRING, '1']];

		$this->service()->save(['enforceHaveIBeenPwned' => false]);

		self::assertSame([['setValueString', 'password_policy', 'enforceHaveIBeenPwned', '0']], $this->writes);
	}//end testASwitchKeptAsAStringIsWrittenAsOneOrZero()

	/**
	 * An unknown key refuses the whole save and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefusedAndNothingIsWritten(): void {
		try {
			$this->service()->save(['minLength' => 12, 'passwordExpiry' => 90]);
			self::fail('An unknown key was accepted.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('Unknown password policy setting: passwordExpiry.', $e->getMessage());
		}

		self::assertSame([], $this->writes);
	}//end testAnUnknownKeyIsRefusedAndNothingIsWritten()

	/**
	 * Values of the wrong kind or out of range are refused.
	 *
	 * @return void
	 */
	public function testBadValuesAreRefused(): void {
		$bad = [
			['minLength' => 0],
			['minLength' => '12'],
			['minLength' => 256],
			['maximumLoginAttempts' => -1],
			['enforceUpperLowerCase' => 1],
			[],
		];
		foreach ($bad as $values) {
			try {
				$this->service()->save($values);
				self::fail('Accepted '.json_encode($values));
			} catch (InvalidArgumentException) {
				self::assertSame([], $this->writes);
			}
		}

		$this->service()->save(['maximumLoginAttempts' => 0]);
		self::assertSame([['setValueInt', 'password_policy', 'maximumLoginAttempts', 0]], $this->writes);
	}//end testBadValuesAreRefused()

	/**
	 * Without the policy app the policy is not available and a save is refused.
	 *
	 * @return void
	 */
	public function testWithoutThePolicyAppThereIsNoPolicy(): void {
		$service = $this->service(enabled: []);

		self::assertSame(['available' => false, 'values' => null], $service->read()['passwordPolicy']);

		$this->expectException(PolicyAppMissingException::class);
		$service->save(['minLength' => 12]);
	}//end testWithoutThePolicyAppThereIsNoPolicy()

	/**
	 * The sign-in methods list both apps, with providers only for an enabled one.
	 *
	 * @return void
	 */
	public function testTheSignInMethodsShowTheEnabledAppAndItsProviders(): void {
		$methods = $this->service(
			enabled: ['user_saml'],
			providers: ['user_saml' => ['Gemeente Zuiddrecht'], 'user_oidc' => ['left over']]
		)->read()['signInMethods'];

		self::assertSame(
			[
				['app' => 'user_saml', 'protocol' => 'saml', 'section' => 'saml', 'enabled' => true, 'providers' => ['Gemeente Zuiddrecht']],
				['app' => 'user_oidc', 'protocol' => 'oidc', 'section' => 'user_oidc', 'enabled' => false, 'providers' => []],
			],
			$methods
		);
	}//end testTheSignInMethodsShowTheEnabledAppAndItsProviders()

	/**
	 * The key list names exactly the `password_policy` keys of the supported Nextcloud range.
	 *
	 * @return void
	 */
	public function testTheKeyListIsThePolicyAppsKeys(): void {
		self::assertSame(
			['minLength', 'enforceUpperLowerCase', 'enforceNumericCharacters', 'enforceSpecialCharacters', 'enforceHaveIBeenPwned', 'maximumLoginAttempts'],
			array_keys(SignInSettingsService::POLICY_KEYS)
		);
	}//end testTheKeyListIsThePolicyAppsKeys()

}//end class
