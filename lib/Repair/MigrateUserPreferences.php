<?php

/**
 * Humaniq Migrate User Preferences Repair Step
 *
 * Repair step that carries this app's per-user preferences across the
 * `hrmq` -> `humaniq` app-id rename.
 *
 * WHY THIS EXISTS SEPARATELY FROM MigrateAppConfigKeys. `IAppConfig` and
 * `IConfig`'s user values are different stores: the former is `oc_appconfig`,
 * the latter `oc_preferences`. Both are namespaced by app id, so both are cut
 * off by the rename, but copying one does nothing for the other.
 *
 * WHY IT MATTERS MORE THAN IT LOOKS. The per-user key this app stores is
 * `active_administration_id`, and `AdministrationService::getActiveAdministration()`
 * reads it with a default of `''` and then falls back to the first
 * administration the user can see. So after the rename the lookup finds
 * nothing, the fallback applies, and in a multi-administratie install the
 * user's HR and payroll surface silently re-scopes to a DIFFERENT LEGAL
 * EMPLOYER than the one they were last working in. Nothing errors, nothing is
 * logged, and every page still renders — it renders someone else's payroll.
 * That is why this is a migration and not a release note.
 *
 * WHY IT ENUMERATES BY USER RATHER THAN BY VALUE. `IConfig` offers no "list
 * every key this app stored for every user" call. It does offer
 * `getUsersForUserValue(app, key, value)` — but that requires the caller to
 * already know the value, which works only for a closed set such as a boolean
 * flag. `active_administration_id` holds an open-ended administration
 * identifier, so there is no finite value list to ask for and enumerating by
 * value would silently migrate nothing. This step therefore walks the users
 * instead (`IUserManager::callForSeenUsers()`) and reads each one's value under
 * the old app id. A key whose possible values are not enumerable by
 * construction must never be migrated by value.
 *
 * `callForSeenUsers()` is the right walk rather than `callForAllUsers()`: a
 * user who has never logged in cannot have set a preference, so the cheaper
 * enumeration is also the complete one for this purpose.
 *
 * If a future release adds another per-user key, it must be added to
 * `MIGRATED_KEYS` — hence the assertion-by-comment there.
 *
 * SAFETY. Idempotent and non-destructive, matching MigrateAppConfigKeys:
 *   - a value is copied only when the user has nothing stored under the new
 *     app id, so a preference changed after the rename is never clobbered and
 *     a second run is a no-op;
 *   - the old `hrmq` rows are never deleted, so a rollback still finds them;
 *   - every failure is logged and the walk continues, because one unreadable
 *     preference is not worth aborting an install over.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` alongside MigrateAppConfigKeys — see the ordering comment
 * there. Unlike the app-config step this one has no ordering relationship with
 * `InitializeRegister`, which never writes user values.
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
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Copy per-user preferences from the hrmq app id to humaniq.
 *
 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
 */
class MigrateUserPreferences implements IRepairStep {

	/**
	 * The preferences namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `hrmq`.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'hrmq';

	/**
	 * The preferences namespace this app uses after the rename.
	 *
	 * @var string
	 */
	private const NEW_APP_ID = 'humaniq';

	/**
	 * Every per-user key this app has ever written.
	 *
	 * Add to this list when a new per-user preference is introduced; a key
	 * missing here is a key that silently resets on the next rename. Values
	 * are deliberately NOT enumerated — see the class docblock on why this
	 * step walks users rather than values.
	 *
	 * `active_administration_id` — `AdministrationService::ACTIVE_ADMINISTRATION_KEY`,
	 * the administration the user is currently working in.
	 *
	 * @var string[]
	 */
	private const MIGRATED_KEYS = ['active_administration_id'];

	/**
	 * Constructor for MigrateUserPreferences.
	 *
	 * @param IConfig         $config      The user-value store to read and write.
	 * @param IUserManager    $userManager The user enumeration used to walk seen users.
	 * @param LoggerInterface $logger      Logger for preferences that fail to copy.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function getName(): string {
		return 'Copy Humaniq per-user preferences from the hrmq app id';
	}//end getName()

	/**
	 * Copy every known per-user preference from the old app id to the new one.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	public function run(IOutput $output): void {
		$migrated = 0;
		$alreadyPresent = 0;

		$walked = $this->walkSeenUsers(
			function (IUser $user) use (&$migrated, &$alreadyPresent): void {
				$userId = $user->getUID();
				foreach (self::MIGRATED_KEYS as $key) {
					try {
						$old = $this->config->getUserValue($userId, self::OLD_APP_ID, $key, '');
						if ($old === '') {
							continue;
						}

						$existing = $this->config->getUserValue($userId, self::NEW_APP_ID, $key, '');
						if ($existing !== '') {
							$alreadyPresent++;
							continue;
						}

						$this->config->setUserValue($userId, self::NEW_APP_ID, $key, $old);
						$migrated++;
					} catch (\Throwable $e) {
						$this->logger->warning(
							'MigrateUserPreferences: could not migrate one preference; leaving it under the old app id.',
							['exception' => $e->getMessage(), 'key' => $key, 'app' => self::NEW_APP_ID]
						);
					}//end try
				}//end foreach
			}
		);

		if ($walked === false) {
			$output->warning(
				'MigrateUserPreferences: could not enumerate users; hrmq preferences were left in place.'
			);
			return;
		}

		if ($migrated === 0 && $alreadyPresent === 0) {
			$output->info('MigrateUserPreferences: no stored hrmq user preferences on this install; nothing to do.');
			return;
		}

		$output->info(
			sprintf(
				'MigrateUserPreferences: migrated %d preference(s); %d already set under humaniq.',
				$migrated,
				$alreadyPresent
			)
		);

	}//end run()

	/**
	 * Walk every user who has logged in at least once.
	 *
	 * A user who has never logged in cannot have set a preference, so
	 * `callForSeenUsers()` is both the cheaper and the complete enumeration
	 * here.
	 *
	 * @param callable(IUser):void $callback Invoked once per seen user.
	 *
	 * @return bool True when the walk completed, false when it could not run.
	 *
	 * @spec openspec/specs/app-identity/spec.md#REQ-AID-002
	 */
	private function walkSeenUsers(callable $callback): bool {
		try {
			$this->userManager->callForSeenUsers($callback);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Humaniq: could not enumerate users; skipping the per-user preference migration',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end walkSeenUsers()

}//end class
