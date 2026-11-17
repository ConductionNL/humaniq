<?php

/**
 * The language humaniq writes to one employee in.
 *
 * The employee's own Nextcloud language always wins. An employee who chose
 * none gets the fallback the setup wizard's Taal step stored as
 * `default_language`, and an install that never answered that step gets
 * Dutch. Notifications and generated documents ask this resolver instead of
 * the instance default, so the Taal step's answer actually reaches them.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-taal-step-shall-set-humaniqs-fallback-language-req-fts-010
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Resolves an employee's language: their own, else humaniq's fallback, else Dutch.
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-taal-step-shall-set-humaniqs-fallback-language-req-fts-010
 */
class LanguageResolver {

	/**
	 * App-config key the Taal step stores the fallback language under.
	 *
	 * @var string
	 */
	public const DEFAULT_LANGUAGE_KEY = 'default_language';

	/**
	 * The language used when neither the employee nor the setup chose one.
	 *
	 * @var string
	 */
	public const FALLBACK = 'nl';

	/**
	 * The fallback languages the Taal step offers.
	 *
	 * @var array<int,string>
	 */
	public const LANGUAGES = ['nl', 'en'];

	/**
	 * Constructor.
	 *
	 * @param IConfig    $config    Holds each user's own Nextcloud language.
	 * @param IAppConfig $appConfig Holds the Taal step's fallback language.
	 * @param IFactory   $l10n      Serves humaniq's catalogue in a given language.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-taal-step-shall-set-humaniqs-fallback-language-req-fts-010
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly IFactory $l10n,
	) {
	}//end __construct()

	/**
	 * The language code humaniq writes to this user in.
	 *
	 * @param string $userId The employee's Nextcloud user id; empty for an employee without an account.
	 *
	 * @return string A language code such as `nl` or `en`.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-the-employees-own-language-wins
	 */
	public function languageFor(string $userId): string {
		if ($userId !== '') {
			$own = trim($this->config->getUserValue($userId, 'core', 'lang', ''));
			if ($own !== '') {
				return $own;
			}
		}

		return $this->fallback();
	}//end languageFor()

	/**
	 * Humaniq's catalogue in the language this user is written to in.
	 *
	 * @param string $userId The employee's Nextcloud user id.
	 *
	 * @return IL10N
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-an-employee-without-a-language-gets-the-fallback
	 */
	public function l10nFor(string $userId): IL10N {
		return $this->l10n->get(Application::APP_ID, $this->languageFor(userId: $userId));
	}//end l10nFor()

	/**
	 * The Taal step's fallback language, or Dutch when it was never answered or holds an unknown value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-an-employee-without-a-language-gets-the-fallback
	 */
	public function fallback(): string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::DEFAULT_LANGUAGE_KEY, '');
		if (in_array($stored, self::LANGUAGES, true) === true) {
			return $stored;
		}

		return self::FALLBACK;
	}//end fallback()
}//end class
