<?php

/**
 * Humaniq SignInSettingsService
 *
 * The values behind the Inloggen section of the humaniq admin settings. Sign-in
 * is Nextcloud's: single sign-on comes from `user_saml` and `user_oidc`, the
 * password policy and the lock after failed attempts from `password_policy`.
 * This service reads and writes that app's own config through IAppConfig and
 * stores no sign-in value of its own, so the Nextcloud Security page and the
 * humaniq section always show the same values.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use OCP\App\IAppManager;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use Throwable;

/**
 * Reads and saves Nextcloud's own sign-in settings.
 *
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
class SignInSettingsService {

	/**
	 * The Nextcloud app that holds the password policy and the lock after failed attempts.
	 */
	public const POLICY_APP = 'password_policy';

	/**
	 * The `password_policy` keys the section shows and saves, with their type and the
	 * value Nextcloud uses while the key is not set. One list, so a key renamed in a
	 * later Nextcloud major shows up in one place and in one test.
	 *
	 * @var array<string, array{type: string, default: int|bool}>
	 */
	public const POLICY_KEYS = [
		'minLength' => ['type' => 'int', 'default' => 10],
		'enforceUpperLowerCase' => ['type' => 'bool', 'default' => false],
		'enforceNumericCharacters' => ['type' => 'bool', 'default' => false],
		'enforceSpecialCharacters' => ['type' => 'bool', 'default' => false],
		'enforceHaveIBeenPwned' => ['type' => 'bool', 'default' => true],
		'maximumLoginAttempts' => ['type' => 'int', 'default' => 0],
	];

	/**
	 * The smallest value each number key accepts. A lock after 0 failed attempts means no lock.
	 *
	 * @var array<string, int>
	 */
	private const MINIMUM = [
		'minLength' => 1,
		'maximumLoginAttempts' => 0,
	];

	/**
	 * The largest value a number key accepts.
	 */
	private const MAXIMUM = 255;

	/**
	 * The sign-in apps the section lists, with their protocol and their admin settings section.
	 *
	 * @var array<string, array{protocol: string, section: string}>
	 */
	public const SIGN_IN_APPS = [
		'user_saml' => ['protocol' => 'saml', 'section' => 'saml'],
		'user_oidc' => ['protocol' => 'oidc', 'section' => 'user_oidc'],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig          $appConfig  Nextcloud's app config, where `password_policy` keeps its values.
	 * @param IAppManager         $appManager Tells which sign-in apps are enabled.
	 * @param SignInProviderNames $providers  Reads the names of the configured identity providers.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly SignInProviderNames $providers,
	) {

	}//end __construct()

	/**
	 * The sign-in methods and the password policy, as the Inloggen section shows them.
	 *
	 * @return array{signInMethods: list<array<string, mixed>>, passwordPolicy: array{available: bool, values: array<string, int|bool>|null}}
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	public function read(): array {
		return [
			'signInMethods' => $this->signInMethods(),
			'passwordPolicy' => $this->passwordPolicy(),
		];
	}//end read()

	/**
	 * Save password policy values in `password_policy`'s own config.
	 *
	 * Only the keys sent are written. An unknown key or a value of the wrong
	 * kind refuses the whole save, so nothing is half written.
	 *
	 * @param array<string, mixed> $values The values by key.
	 *
	 * @return array{signInMethods: list<array<string, mixed>>, passwordPolicy: array{available: bool, values: array<string, int|bool>|null}}
	 *
	 * @throws PolicyAppMissingException When `password_policy` is not enabled.
	 * @throws InvalidArgumentException  When a key is unknown or a value is out of range.
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-006
	 */
	public function save(array $values): array {
		if ($this->policyAppEnabled() === false) {
			throw new PolicyAppMissingException('The password policy needs the Nextcloud password policy app.');
		}

		$checked = $this->checked(values: $values);
		foreach ($checked as $key => $value) {
			$this->write(key: $key, value: $value);
		}

		return $this->read();
	}//end save()

	/**
	 * The values to write, checked against the key list.
	 *
	 * @param array<string, mixed> $values The values by key.
	 *
	 * @return array<string, int|bool>
	 *
	 * @throws InvalidArgumentException When a key is unknown or a value is out of range.
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function checked(array $values): array {
		if ($values === []) {
			throw new InvalidArgumentException('Send at least one password policy value.');
		}

		$unknown = array_diff(array_map('strval', array_keys($values)), array_keys(self::POLICY_KEYS));
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown password policy setting: '.implode(', ', $unknown).'.');
		}

		$checked = [];
		foreach ($values as $key => $value) {
			$checked[$key] = $this->checkedValue(key: $key, value: $value);
		}

		return $checked;
	}//end checked()

	/**
	 * One value, checked for its key's type and range.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value sent.
	 *
	 * @return int|bool
	 *
	 * @throws InvalidArgumentException When the value is of the wrong kind or out of range.
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function checkedValue(string $key, mixed $value): int|bool {
		if (self::POLICY_KEYS[$key]['type'] === 'bool') {
			if (is_bool($value) === false) {
				throw new InvalidArgumentException($key.' must be true or false.');
			}

			return $value;
		}

		if (is_int($value) === false || $value < self::MINIMUM[$key] || $value > self::MAXIMUM) {
			throw new InvalidArgumentException($key.' must be a whole number from '.self::MINIMUM[$key].' to '.self::MAXIMUM.'.');
		}

		return $value;
	}//end checkedValue()

	/**
	 * Write one value in the type `password_policy` already keeps it in.
	 *
	 * A key stored as a string or as an untyped (mixed) value is written as a
	 * string, so the policy app reads it as before; a typed or unset key is
	 * written in its natural type.
	 *
	 * @param string   $key   The key.
	 * @param int|bool $value The value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function write(string $key, int|bool $value): void {
		try {
			$type = $this->appConfig->getValueType(self::POLICY_APP, $key);
		} catch (AppConfigUnknownKeyException) {
			$type = 0;
		}

		if (($type & (IAppConfig::VALUE_STRING | IAppConfig::VALUE_MIXED)) !== 0) {
			$this->appConfig->setValueString(self::POLICY_APP, $key, $this->asString(value: $value));
			return;
		}

		if (is_bool($value) === true) {
			$this->appConfig->setValueBool(self::POLICY_APP, $key, $value);
			return;
		}

		$this->appConfig->setValueInt(self::POLICY_APP, $key, $value);
	}//end write()

	/**
	 * A value as the policy app writes it in a string: `1`/`0` for a switch.
	 *
	 * @param int|bool $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function asString(int|bool $value): string {
		if (is_bool($value) === true) {
			return ($value === true) ? '1' : '0';
		}

		return (string) $value;
	}//end asString()

	/**
	 * The password policy: whether its app is enabled, and its values when it is.
	 *
	 * @return array{available: bool, values: array<string, int|bool>|null}
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function passwordPolicy(): array {
		if ($this->policyAppEnabled() === false) {
			return ['available' => false, 'values' => null];
		}

		$stored = $this->appConfig->getAllValues(self::POLICY_APP);
		$values = [];
		foreach (self::POLICY_KEYS as $key => $definition) {
			$values[$key] = $this->typed(value: ($stored[$key] ?? null), definition: $definition);
		}

		return ['available' => true, 'values' => $values];
	}//end passwordPolicy()

	/**
	 * A stored value in its key's type, or the key's default when it is unset or unreadable.
	 *
	 * @param mixed                                  $value      The stored value.
	 * @param array{type: string, default: int|bool} $definition The key's type and default.
	 *
	 * @return int|bool
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function typed(mixed $value, array $definition): int|bool {
		if ($definition['type'] === 'bool') {
			if (is_bool($value) === true) {
				return $value;
			}

			if (is_int($value) === true || is_string($value) === true) {
				return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
			}

			return $definition['default'];
		}

		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && preg_match('/^\s*\d+\s*$/', $value) === 1) {
			return (int) $value;
		}

		return $definition['default'];
	}//end typed()

	/**
	 * The sign-in apps, whether each is enabled and the names of its providers.
	 *
	 * @return list<array{app: string, protocol: string, section: string, enabled: bool, providers: list<string>}>
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
	 */
	private function signInMethods(): array {
		$methods = [];
		foreach (self::SIGN_IN_APPS as $app => $method) {
			$enabled   = $this->enabled(app: $app);
			$methods[] = [
				'app' => $app,
				'protocol' => $method['protocol'],
				'section' => $method['section'],
				'enabled' => $enabled,
				'providers' => ($enabled === true) ? $this->providers->namesFor(app: $app) : [],
			];
		}

		return $methods;
	}//end signInMethods()

	/**
	 * Whether `password_policy` is enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
	 */
	private function policyAppEnabled(): bool {
		return $this->enabled(app: self::POLICY_APP);
	}//end policyAppEnabled()

	/**
	 * Whether an app is enabled on this instance; an app manager that fails answers no.
	 *
	 * @param string $app The app id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
	 */
	private function enabled(string $app): bool {
		try {
			return $this->appManager->isEnabledForAnyone($app);
		} catch (Throwable) {
			return false;
		}
	}//end enabled()

}//end class
