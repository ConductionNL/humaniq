<?php

/**
 * Humaniq HumaniqRoles
 *
 * The one role check for humaniq's own endpoints (compliance-roles-and-field-access
 * D1 and D2). HR is the Nextcloud group `humaniq-hr`, payroll the group
 * `humaniq-payroll`; a Nextcloud administrator holds every role. The group
 * ids are fixed rather than configurable because OpenRegister's property
 * authorization in the register names them literally: a setting that pointed
 * humaniq at another group would leave the register guarding the old one.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IGroupManager;

/**
 * Answers whether a user is HR, payroll, or either.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */
class HumaniqRoles {

	/**
	 * The HR group.
	 *
	 * @var string
	 */
	public const HR_GROUP = 'humaniq-hr';

	/**
	 * The payroll group.
	 *
	 * @var string
	 */
	public const PAYROLL_GROUP = 'humaniq-payroll';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Group membership and the administrator check.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Whether the user may take HR actions.
	 *
	 * @param string|null $uid The user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
	 */
	public function isHr(?string $uid): bool {
		return $this->holds($uid, self::HR_GROUP);
	}//end isHr()

	/**
	 * Whether the user may take payroll actions.
	 *
	 * @param string|null $uid The user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
	 */
	public function isPayroll(?string $uid): bool {
		return $this->holds($uid, self::PAYROLL_GROUP);
	}//end isPayroll()

	/**
	 * Whether the user may take HR or payroll actions.
	 *
	 * @param string|null $uid The user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
	 */
	public function isHrOrPayroll(?string $uid): bool {
		return $this->isHr($uid) === true || $this->isPayroll($uid) === true;
	}//end isHrOrPayroll()

	/**
	 * Whether the user is an administrator or in the group.
	 *
	 * @param string|null $uid   The user.
	 * @param string      $group The group.
	 *
	 * @return bool
	 */
	private function holds(?string $uid, string $group): bool {
		if ($uid === null || $uid === '') {
			return false;
		}

		return $this->groupManager->isAdmin($uid) === true || $this->groupManager->isInGroup($uid, $group) === true;
	}//end holds()

}//end class
