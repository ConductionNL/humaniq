<?php

/**
 * Humaniq AccessRevocationService
 *
 * Disables a leaver's Nextcloud account through Nextcloud's own user manager
 * and records it on the offboarding case (hiring-offboarding-completion D2).
 * The account comes only from `Employee.nextcloudUserId`. An account in the
 * admin group and the acting user's own account are refused; an employee
 * without an account ticks the box with that said; a repeat call changes
 * nothing. Disabling keeps the account's data.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Revokes access on one case, or on every due case.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */
class AccessRevocationService {

	/**
	 * The actor recorded when the daily flow revokes.
	 */
	public const SYSTEM_ACTOR = 'humaniq';

	private const SCHEMA = 'Offboarding';

	/**
	 * Case states in which the flow still acts.
	 */
	private const OPEN_STATES = ['aangekondigd', 'afronding_gepland', 'eindafrekening_gereed'];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway The register read and write.
	 * @param IUserManager         $users   Nextcloud's user manager.
	 * @param IGroupManager        $groups  Whether an account is an administrator.
	 * @param LoggerInterface      $logger  The logger.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Disable the leaver's account of one case and stamp the case. The caller
	 * has resolved the case under its own rights first.
	 *
	 * @param string $offboardingId The case.
	 * @param string $actorUid      Who revokes.
	 * @param string $now           The moment, ISO 8601.
	 *
	 * @return array{status: int, message: ?string, offboarding: ?array<string, mixed>} 200, or 404/409 with the reason.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function revoke(string $offboardingId, string $actorUid, string $now): array {
		$case = $this->gateway->findObjectData($offboardingId, self::SCHEMA);
		if ($case === null) {
			return ['status' => 404, 'message' => 'Offboarding case not found.', 'offboarding' => null];
		}

		$run = new AccessRevocationRun();
		$run->offboardingId = $offboardingId;
		$run->case = $case;
		$run->actorUid = $actorUid;
		$run->now = $now;

		return $this->revokeAccountOf(run: $run);
	}//end revoke()

	/**
	 * Disable the account of the employee the case names, or say why not.
	 *
	 * @param AccessRevocationRun $run The case being revoked.
	 *
	 * @return array{status: int, message: ?string, offboarding: ?array<string, mixed>}
	 */
	private function revokeAccountOf(AccessRevocationRun $run): array {
		$employee = $this->gateway->findObjectData((string)($run->case['employeeId'] ?? ''), 'Employee');
		if ($employee === null) {
			return ['status' => 409, 'message' => 'The case names no employee record.', 'offboarding' => null];
		}

		$uid = trim((string)($employee['nextcloudUserId'] ?? ''));
		if ($uid === '') {
			return $this->stamp(run: $run, note: 'No Nextcloud account');
		}

		return ($this->refusalFor(run: $run, uid: $uid) ?? $this->disable(run: $run, uid: $uid));
	}//end revokeAccountOf()

	/**
	 * Why the account may not be disabled from humaniq: it is the actor's own
	 * or an administrator's; null when neither.
	 *
	 * @param AccessRevocationRun $run The case being revoked.
	 * @param string              $uid The leaver's account.
	 *
	 * @return array{status: int, message: string, offboarding: null}|null
	 */
	private function refusalFor(AccessRevocationRun $run, string $uid): ?array {
		if ($uid === $run->actorUid) {
			return ['status' => 409, 'message' => 'You cannot disable your own account.', 'offboarding' => null];
		}

		if ($this->groups->isAdmin($uid) === true) {
			return ['status' => 409, 'message' => 'The account ' . $uid . ' is an administrator and is not disabled from humaniq. Disable it in Nextcloud user management.', 'offboarding' => null];
		}

		return null;
	}//end refusalFor()

	/**
	 * Disable the account and stamp the case; a case already stamped whose
	 * account is disabled is answered as it is.
	 *
	 * @param AccessRevocationRun $run The case being revoked.
	 * @param string              $uid The leaver's account.
	 *
	 * @return array{status: int, message: ?string, offboarding: ?array<string, mixed>}
	 */
	private function disable(AccessRevocationRun $run, string $uid): array {
		$user = $this->users->get($uid);
		if ($user === null) {
			return ['status' => 409, 'message' => 'Nextcloud has no account ' . $uid . '. Correct the employee\'s Nextcloud account first.', 'offboarding' => null];
		}

		if (self::isAlreadyRevoked(run: $run, user: $user) === true) {
			return ['status' => 200, 'message' => null, 'offboarding' => $run->case];
		}

		$user->setEnabled(false);
		$this->logger->info('humaniq: disabled the account ' . $uid . ' of offboarding case ' . $run->offboardingId . ' for ' . $run->actorUid);

		return $this->stamp(run: $run, note: null);
	}//end disable()

	/**
	 * Whether the account is disabled and the case already says so.
	 *
	 * @param AccessRevocationRun $run  The case being revoked.
	 * @param IUser               $user The leaver's account.
	 *
	 * @return bool
	 */
	private static function isAlreadyRevoked(AccessRevocationRun $run, IUser $user): bool {
		return $user->isEnabled() === false && ($run->case['toegangIngetrokken'] ?? false) === true;
	}//end isAlreadyRevoked()

	/**
	 * Revoke every open case whose last working day has passed and whose
	 * access is not revoked yet. A refused case is logged and left.
	 *
	 * @param string $today The day, `YYYY-MM-DD`.
	 * @param string $now   The moment, ISO 8601.
	 *
	 * @return array<int, string> The cases revoked.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function revokeDue(string $today, string $now): array {
		$done = [];
		foreach ($this->gateway->loadAll(self::SCHEMA) as $case) {
			if (self::isDue(case: $case, today: $today) === false) {
				continue;
			}

			$id = (string)($case['id'] ?? '');
			$result = $this->revoke(offboardingId: $id, actorUid: self::SYSTEM_ACTOR, now: $now);
			if ($result['status'] !== 200) {
				$this->logger->warning('humaniq: offboarding case ' . $id . ' was not revoked: ' . (string)$result['message']);
				continue;
			}

			$done[] = $id;
		}

		return $done;
	}//end revokeDue()

	/**
	 * Whether a case is open, not revoked yet, and past its last working day.
	 *
	 * @param array<string, mixed> $case  The stored case.
	 * @param string               $today The day, `YYYY-MM-DD`.
	 *
	 * @return bool
	 */
	private static function isDue(array $case, string $today): bool {
		$lastDay = (string)($case['lastWorkingDay'] ?? '');

		return in_array((string)($case['status'] ?? ''), self::OPEN_STATES, true) === true
			&& ($case['toegangIngetrokken'] ?? false) !== true
			&& $lastDay !== ''
			&& $lastDay < $today;
	}//end isDue()

	/**
	 * Save the whole case with the stamp: OpenRegister replaces the object, so
	 * the stamp goes on top of what is stored.
	 *
	 * @param AccessRevocationRun $run  The case being revoked.
	 * @param string|null         $note Why there was nothing to disable.
	 *
	 * @return array{status: int, message: ?string, offboarding: ?array<string, mixed>}
	 */
	private function stamp(AccessRevocationRun $run, ?string $note): array {
		$case = $run->case;
		unset($case['id'], $case['@self']);
		$case['toegangIngetrokken'] = true;
		$case['toegangIngetrokkenDoor'] = $run->actorUid;
		$case['toegangIngetrokkenOp'] = $run->now;
		$case['toegangIngetrokkenToelichting'] = $note;
		$this->gateway->save($case, self::SCHEMA, $run->offboardingId);

		return ['status' => 200, 'message' => $note, 'offboarding' => $case];
	}//end stamp()
}//end class
