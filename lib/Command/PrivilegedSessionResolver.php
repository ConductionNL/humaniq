<?php

/**
 * Privileged Session Resolver
 *
 * The shared `--as-user` privileged-session establishment mechanism
 * (avg-dsr design.md D3) every `occ humaniq:avg:*` command uses BEFORE calling
 * `AvgDsrService`/OpenRegister's `DsarService`: there is no ambient
 * Nextcloud request in a plain CLI invocation, hence no session
 * `IUserSession::getUser()` could return, and `DsarService::assertPrivileged()`
 * throws unless one resolves to a real administrator. This resolver
 * validates the named uid is a real, actual Nextcloud administrator
 * (`IUserManager::get()` + `IGroupManager::isAdmin()`) and calls
 * `IUserSession::setUser()` BEFORE returning success -- every failure mode
 * (unknown user, non-admin user) is a one-line controlled message, never an
 * uncaught throw, and `DsarService` is never invoked before this succeeds
 * (REQ-DSR-004).
 *
 * `grep -rn IUserSession lib/Command/` (design.md Context) confirmed no
 * existing humaniq command established a user session -- this mechanism is
 * genuinely new to this codebase, not a reused pattern.
 *
 * @category Command
 * @package  OCA\Humaniq\Command
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Resolves, validates, and establishes the `--as-user` privileged session
 * shared by every `occ humaniq:avg:*` command.
 */
final class PrivilegedSessionResolver {

	/**
	 * @param IUserManager $userManager Resolves the named uid to a real user.
	 * @param IGroupManager $groupManager Validates the resolved user is an actual Nextcloud administrator.
	 * @param IUserSession $userSession Session `DsarService::assertPrivileged()` reads via `getUser()`.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * Resolve, validate, and establish the privileged session for `$uid`.
	 *
	 * @param string $uid The `--as-user` value.
	 *
	 * @return string|null A one-line controlled error message on failure, or null on success (the session is now established).
	 *
	 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-004
	 */
	public function establish(string $uid): ?string {
		$uid = trim($uid);
		if ($uid === '') {
			return '--as-user is verplicht.';
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return 'Onbekende gebruiker \'' . $uid . '\'.';
		}

		if ($this->groupManager->isAdmin($uid) === false) {
			return '\'' . $uid . '\' is geen Nextcloud-beheerder; AVG data-subject-rights-bewerkingen vereisen een beheerderssessie.';
		}

		$this->userSession->setUser($user);

		return null;
	}//end establish()

}//end class
