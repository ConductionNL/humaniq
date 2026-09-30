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
	 * @param HoursRegisterGateway $gateway The register read and write.
	 * @param IUserManager         $users   Nextcloud's user manager.
	 * @param IGroupManager        $groups  Whether an account is an administrator.
	 * @param LoggerInterface      $logger  The logger.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
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

		$employee = $this->gateway->findObjectData((string)($case['employeeId'] ?? ''), 'Employee');
		if ($employee === null) {
			return ['status' => 409, 'message' => 'The case names no employee record.', 'offboarding' => null];
		}

		$uid = trim((string)($employee['nextcloudUserId'] ?? ''));
		if ($uid === '') {
			return $this->stamp(offboardingId: $offboardingId, case: $case, actorUid: $actorUid, now: $now, note: 'No Nextcloud account');
		}

		if ($uid === $actorUid) {
			return ['status' => 409, 'message' => 'You cannot disable your own account.', 'offboarding' => null];
		}

		if ($this->groups->isAdmin($uid) === true) {
			return ['status' => 409, 'message' => 'The account ' . $uid . ' is an administrator and is not disabled from humaniq. Disable it in Nextcloud user management.', 'offboarding' => null];
		}

		$user = $this->users->get($uid);
		if ($user === null) {
			return ['status' => 409, 'message' => 'Nextcloud has no account ' . $uid . '. Correct the employee\'s Nextcloud account first.', 'offboarding' => null];
		}

		if ($user->isEnabled() === false && ($case['toegangIngetrokken'] ?? false) === true) {
			return ['status' => 200, 'message' => null, 'offboarding' => $case];
		}

		$user->setEnabled(false);
		$this->logger->info('humaniq: disabled the account ' . $uid . ' of offboarding case ' . $offboardingId . ' for ' . $actorUid);

		return $this->stamp(offboardingId: $offboardingId, case: $case, actorUid: $actorUid, now: $now, note: null);
	}//end revoke()

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
			$lastDay = (string)($case['lastWorkingDay'] ?? '');
			if (in_array((string)($case['status'] ?? ''), self::OPEN_STATES, true) === false
				|| ($case['toegangIngetrokken'] ?? false) === true
				|| $lastDay === ''
				|| $lastDay >= $today
			) {
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
	 * Save the whole case with the stamp: OpenRegister replaces the object, so
	 * the stamp goes on top of what is stored.
	 *
	 * @param string               $offboardingId The case.
	 * @param array<string, mixed> $case          The stored case.
	 * @param string               $actorUid      Who revoked.
	 * @param string               $now           When.
	 * @param string|null          $note          Why there was nothing to disable.
	 *
	 * @return array{status: int, message: ?string, offboarding: ?array<string, mixed>}
	 */
	private function stamp(string $offboardingId, array $case, string $actorUid, string $now, ?string $note): array {
		unset($case['id'], $case['@self']);
		$case['toegangIngetrokken'] = true;
		$case['toegangIngetrokkenDoor'] = $actorUid;
		$case['toegangIngetrokkenOp'] = $now;
		$case['toegangIngetrokkenToelichting'] = $note;
		$this->gateway->save($case, self::SCHEMA, $offboardingId);

		return ['status' => 200, 'message' => $note, 'offboarding' => $case];
	}//end stamp()
}//end class
