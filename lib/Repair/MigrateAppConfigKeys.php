<?php

/**
 * Humaniq Migrate App Config Keys Repair Step
 *
 * Repair step that carries this app's stored `IAppConfig` values across the
 * `hrmq` -> `humaniq` app-id rename.
 *
 * Nextcloud namespaces `IAppConfig` by app id at the storage layer
 * (`oc_appconfig.appid`), so renaming `<id>` does not rename the rows — it
 * makes every previously stored value unreachable, because the app now asks
 * for them under a different app id. There is no in-place app-id upgrade in
 * Nextcloud: the new id is simply a different app. This step therefore copies
 * each value from the old namespace to the new one.
 *
 * WHY EVERY KEY RATHER THAN A FIXED LIST. `SettingsService` writes the admin
 * settings, but that is not the whole stored set — the register import records
 * the imported register/schema ids and the fragment-content signature, and past
 * releases have written keys this app no longer reads. Enumerating
 * `IAppConfig::getKeys()` is exhaustive by construction and cannot drift out of
 * date the way a hardcoded list does.
 *
 * ORDERING. Registered BEFORE `InitializeRegister` in both blocks of
 * `appinfo/info.xml`, and that order is load-bearing: `InitializeRegister`
 * writes the imported register/schema-id bookkeeping keys itself, so running it
 * first would leave those keys already present under `humaniq` and this step
 * would skip them as "already present", stranding the old values.
 *
 * SAFETY. Idempotent and non-destructive:
 *   - a key is copied only when the old value is non-empty AND the new
 *     namespace does not already hold a value, so an admin edit made after the
 *     rename is never clobbered and a second run is a no-op;
 *   - the old `hrmq` rows are never deleted, so a rollback to the previous
 *     app id still finds its configuration intact;
 *   - values round-trip as raw strings. `IAppConfig` stores every value as a
 *     string and the typed accessors only coerce on read, so a string
 *     round-trip cannot lose or corrupt a value written by a typed setter;
 *   - every failure is logged and the loop continues. A repair step that
 *     throws aborts the install, and a config value that could not be copied
 *     is not worth failing an install over — the app falls back to its
 *     defaults and the admin can re-enter the setting.
 *
 * @category Repair
 * @package  OCA\Humaniq\Repair
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
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Copy every stored IAppConfig value from the hrmq namespace to humaniq.
 *
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
 */
class MigrateAppConfigKeys implements IRepairStep {

	/**
	 * The app-config namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `hrmq`.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'hrmq';

	/**
	 * The app-config namespace this app uses after the rename.
	 *
	 * @var string
	 */
	private const NEW_APP_ID = 'humaniq';

	/**
	 * Config keys Nextcloud owns for every app. These MUST NOT be copied.
	 *
	 * `AppManager::enableApp()` writes `enabled` through the deprecated
	 * `IAppConfig::setValue()`, which stores type MIXED. Copying it here with
	 * `setValueString()` stores type STRING, and the next `app:enable` then
	 * fails with an `AppConfigTypeConflictException` — permanently, because the
	 * conflict is hit before the app can run anything that would repair it.
	 * `installed_version` and `types` are Nextcloud's own bookkeeping for the
	 * app and copying the old app's values would misreport the new one.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	/**
	 * Constructor for MigrateAppConfigKeys.
	 *
	 * @param IAppConfig      $appConfig The app config store to read and write.
	 * @param LoggerInterface $logger    Logger for keys that fail to copy.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function getName(): string {
		return 'Copy Humaniq app configuration from the hrmq namespace to humaniq';
	}//end getName()

	/**
	 * Run the repair step to migrate the stored app configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	public function run(IOutput $output): void {
		$keys = $this->oldKeys();
		if ($keys === []) {
			$output->info(
				'MigrateAppConfigKeys: no stored hrmq configuration on this install; nothing to do.'
			);
			return;
		}

		$migrated = 0;
		$alreadyPresent = 0;
		$emptySource = 0;
		$skippedReserved = 0;

		foreach ($keys as $key) {
			if (in_array($key, self::RESERVED_KEYS, strict: true) === true) {
				$skippedReserved++;
				continue;
			}

			$old = $this->appConfig->getValueString(self::OLD_APP_ID, $key, '');
			if ($old === '') {
				$emptySource++;
				continue;
			}

			$existing = $this->appConfig->getValueString(self::NEW_APP_ID, $key, '');
			if ($existing !== '') {
				$alreadyPresent++;
				continue;
			}

			try {
				$this->appConfig->setValueString(self::NEW_APP_ID, $key, $old);
				$migrated++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Humaniq: could not migrate one app config key; leaving it under the old namespace',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			'MigrateAppConfigKeys: ' . $migrated . ' key(s) migrated, ' . $alreadyPresent
			. ' already present, ' . $emptySource . ' had no value to migrate, '
			. $skippedReserved . ' skipped as Nextcloud-reserved.'
		);

	}//end run()

	/**
	 * Every key currently stored under the old app-config namespace.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-001
	 */
	private function oldKeys(): array {
		try {
			return $this->appConfig->getKeys(self::OLD_APP_ID);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Humaniq: could not enumerate hrmq app config keys; skipping the migration',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end oldKeys()

}//end class
