<?php

/**
 * Humaniq EnsureRoleGroups
 *
 * Repair step that creates the Nextcloud groups `humaniq-hr` and
 * `humaniq-payroll` when they are absent (compliance-roles-and-field-access
 * D1). Idempotent: an existing group, and its members, are left alone.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Service\HumaniqRoles;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Creates the HR and payroll groups once.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */
class EnsureRoleGroups implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Creates the groups.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Create the humaniq HR and payroll groups';
	}//end getName()

	/**
	 * Create each group that does not exist yet.
	 *
	 * @param IOutput $output Where to report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
	 */
	public function run(IOutput $output): void {
		foreach ([HumaniqRoles::HR_GROUP => 'humaniq HR', HumaniqRoles::PAYROLL_GROUP => 'humaniq payroll'] as $gid => $name) {
			if ($this->groupManager->groupExists($gid) === true) {
				continue;
			}

			$group = $this->groupManager->createGroup($gid);
			if ($group !== null && method_exists($group, 'setDisplayName') === true) {
				$group->setDisplayName($name);
			}

			$output->info('Created group ' . $gid . '.');
		}
	}//end run()

}//end class
