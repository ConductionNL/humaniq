<?php

/**
 * Administration Access Trust
 *
 * Decides which AdministrationAccess rows humaniq believes. A row counts only
 * when it was granted by a Nextcloud administrator, by OpenRegister's system
 * identity (a shipped import or a job without a session), or by someone who
 * already holds a believed `hr` row for the same administration. A row a user
 * wrote for themselves without that standing grants nothing.
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IAppConfig;

/**
 * Filters AdministrationAccess rows down to the ones granted with standing.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */
class AdministrationAccessTrust {

	/**
	 * The role that may grant access to its administration.
	 *
	 * @var string
	 */
	public const GRANTING_ROLE = 'hr';

	/**
	 * OpenRegister's default system identity, used when none is configured.
	 *
	 * @var string
	 */
	private const SYSTEM_USER_DEFAULT = '__system__';

	/**
	 * @param HumaniqRoles $roles     The administrator check.
	 * @param IAppConfig   $appConfig Reads OpenRegister's configured system identity.
	 */
	public function __construct(
		private readonly HumaniqRoles $roles,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The rows granted with standing, in their original order.
	 *
	 * A row is believed when its owner is an administrator, the system
	 * identity, or a user with a believed `hr` row for the same
	 * administration. The last rule is applied until nothing more is added,
	 * so a chain of HR grants that starts at an administrator holds.
	 *
	 * @param array<int, array<string, mixed>> $rows Every AdministrationAccess row, with `@self.owner`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function trusted(array $rows): array {
		$systemUser = $this->systemUser();
		$believed = [];
		$granters = [];

		do {
			$added = false;
			foreach ($rows as $index => $row) {
				if (isset($believed[$index]) === true) {
					continue;
				}

				$administrationId = (string)($row['administrationId'] ?? '');
				$owner = self::ownerOf($row);
				if ($owner === '' || $administrationId === '') {
					continue;
				}

				$standing = ($owner === $systemUser || isset($granters[$administrationId][$owner]) === true || $this->roles->isAdmin($owner) === true);
				if ($standing === false) {
					continue;
				}

				$believed[$index] = $row;
				$added = true;
				if (trim((string)($row['role'] ?? '')) === self::GRANTING_ROLE) {
					$granters[$administrationId][(string)($row['userId'] ?? '')] = true;
				}
			}
		} while ($added === true);

		ksort($believed);
		return array_values($believed);

	}//end trusted()

	/**
	 * Whether the user may grant or withdraw access to the administration:
	 * an administrator, or a user with a believed `hr` row for it.
	 *
	 * @param string                           $userId           The acting user.
	 * @param string                           $administrationId The administration the write is about.
	 * @param array<int, array<string, mixed>> $rows             Every AdministrationAccess row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function mayGrant(string $userId, string $administrationId, array $rows): bool {
		if ($userId === '' || $administrationId === '') {
			return false;
		}

		if ($this->roles->isAdmin($userId) === true) {
			return true;
		}

		foreach ($this->trusted($rows) as $row) {
			if ((string)($row['userId'] ?? '') === $userId
				&& (string)($row['administrationId'] ?? '') === $administrationId
				&& trim((string)($row['role'] ?? '')) === self::GRANTING_ROLE
			) {
				return true;
			}
		}

		return false;

	}//end mayGrant()

	/**
	 * The owner OpenRegister stamped on the row, or ''.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function ownerOf(array $row): string {
		$self = ($row['@self'] ?? null);
		if (is_array($self) === false) {
			return '';
		}

		return trim((string)($self['owner'] ?? ''));

	}//end ownerOf()

	/**
	 * OpenRegister's system identity.
	 *
	 * @return string
	 */
	private function systemUser(): string {
		$configured = $this->appConfig->getValueString('openregister', 'systemUserId', '');

		return $configured !== '' ? $configured : self::SYSTEM_USER_DEFAULT;

	}//end systemUser()
}//end class
